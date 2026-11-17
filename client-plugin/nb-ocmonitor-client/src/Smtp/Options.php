<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp;

/**
 * Instellingen van de SMTP-module in de optie nb_ocm_smtp (autoload uit). Het wachtwoord staat
 * versleuteld opgeslagen (Crypto). Constants in wp-config.php gaan voor en vergrendelen het veld:
 * NB_OCM_SMTP_HOST, _PORT, _ENCRYPTION, _AUTH, _USER, _PASS, _FROM, _FROM_NAME.
 */
final class Options
{
    public const OPTION = 'nb_ocm_smtp';
    public const ENCRYPTIONS = ['none' => 'Geen', 'ssl' => 'SSL/TLS (meestal poort 465)', 'tls' => 'STARTTLS (meestal poort 587)'];

    /** veld => constant */
    public const CONSTANTS = [
        'host' => 'NB_OCM_SMTP_HOST',
        'port' => 'NB_OCM_SMTP_PORT',
        'encryption' => 'NB_OCM_SMTP_ENCRYPTION',
        'auth' => 'NB_OCM_SMTP_AUTH',
        'user' => 'NB_OCM_SMTP_USER',
        'pass' => 'NB_OCM_SMTP_PASS',
        'from_email' => 'NB_OCM_SMTP_FROM',
        'from_name' => 'NB_OCM_SMTP_FROM_NAME',
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'from_email' => '',
            'from_name' => '',
            'force_from_email' => true,
            'force_from_name' => false,
            'return_path' => true,
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'autotls' => true,
            'auth' => true,
            'user' => '',
            'pass' => '', // versleuteld
        ];
    }

    /**
     * Opgeslagen of ingevoerde waarden controleren. pass wordt ongewijzigd doorgegeven (versleuteld).
     *
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function normalize($value): array
    {
        $value = is_array($value) ? $value : [];
        $d = self::defaults();
        $text = static function ($v, int $max): string {
            $v = is_scalar($v) ? trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $v) ?? '') : '';

            return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
        };
        $email = $text($value['from_email'] ?? '', 191);
        $host = strtolower($text($value['host'] ?? '', 191));
        $encryption = (string) ($value['encryption'] ?? $d['encryption']);
        $port = is_numeric($value['port'] ?? null) ? (int) $value['port'] : $d['port'];

        return [
            'from_email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'from_name' => $text($value['from_name'] ?? '', 120),
            'force_from_email' => (bool) ($value['force_from_email'] ?? $d['force_from_email']),
            'force_from_name' => (bool) ($value['force_from_name'] ?? $d['force_from_name']),
            'return_path' => (bool) ($value['return_path'] ?? $d['return_path']),
            'host' => preg_match('/^[a-z0-9.\-]+$|^\[[0-9a-f:.]+\]$/', $host) === 1 ? $host : '',
            'port' => $port >= 1 && $port <= 65535 ? $port : $d['port'],
            'encryption' => isset(self::ENCRYPTIONS[$encryption]) ? $encryption : $d['encryption'],
            'autotls' => (bool) ($value['autotls'] ?? $d['autotls']),
            'auth' => (bool) ($value['auth'] ?? $d['auth']),
            'user' => $text($value['user'] ?? '', 191),
            'pass' => is_string($value['pass'] ?? null) ? $value['pass'] : '',
        ];
    }

    /** @return array<string, mixed> opgeslagen waarden met constants erover; pass blijft versleuteld */
    public static function get(): array
    {
        $o = self::normalize(get_option(self::OPTION));
        foreach (self::locked() as $field => $constant) {
            if ($field === 'pass') {
                continue;
            }
            $o = self::normalize(array_merge($o, [$field => constant($constant)]));
        }

        return $o;
    }

    /** Het wachtwoord in leesbare vorm, of null als het niet te ontsleutelen is. */
    public static function password(): ?string
    {
        if (defined(self::CONSTANTS['pass'])) {
            return (string) constant(self::CONSTANTS['pass']);
        }

        return Crypto::decrypt((string) self::normalize(get_option(self::OPTION))['pass']);
    }

    /** @return array<string, string> velden die in wp-config.php staan */
    public static function locked(): array
    {
        return array_filter(self::CONSTANTS, 'defined');
    }

    /**
     * Opslaan vanuit het formulier. Een leeg wachtwoordveld laat het opgeslagen wachtwoord staan,
     * tenzij clear_pass is aangevinkt.
     *
     * @param array<string, mixed> $input
     * @return array{from: array<string, mixed>, to: array<string, mixed>}
     */
    public static function save(array $input): array
    {
        $from = self::normalize(get_option(self::OPTION));
        $new = $input;
        foreach (['force_from_email', 'force_from_name', 'return_path', 'autotls', 'auth'] as $bool) {
            $new[$bool] = !empty($input[$bool]);
        }
        $pass = (string) ($input['pass'] ?? '');
        if (!empty($input['clear_pass'])) {
            $new['pass'] = '';
        } elseif ($pass !== '') {
            $new['pass'] = Crypto::encrypt($pass);
        } else {
            $new['pass'] = $from['pass'];
        }
        foreach (array_keys(self::locked()) as $field) {
            $new[$field] = $from[$field]; // vergrendelde velden niet via het formulier wijzigen
        }
        $to = self::normalize($new);
        update_option(self::OPTION, $to, false);

        return ['from' => $from, 'to' => $to];
    }

    /** Is er genoeg ingevuld om via SMTP te versturen? */
    public static function isComplete(?array $o = null): bool
    {
        $o = $o ?? self::get();

        return $o['host'] !== '' && $o['port'] > 0 && (!$o['auth'] || $o['user'] !== '');
    }

    /**
     * Omschrijving voor het wijzigingslog, zonder geheimen.
     *
     * @param array<string, mixed> $o
     */
    public static function describe(array $o): string
    {
        if ($o['host'] === '') {
            return 'niet ingesteld';
        }

        return sprintf(
            '%s:%d %s%s, afzender %s',
            $o['host'],
            $o['port'],
            $o['encryption'],
            $o['auth'] ? ' als ' . $o['user'] : ' zonder login',
            $o['from_email'] !== '' ? $o['from_email'] : '(WordPress)'
        );
    }
}
