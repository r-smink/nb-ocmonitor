<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp;

/**
 * Loopt de SMTP-sessie stap voor stap door zonder een mail te versturen: servernaam opzoeken,
 * verbinden, begroeting, versleuteling, inloggen. Zo zie je precies waar het misgaat.
 */
final class Preflight
{
    /**
     * @param array<string, mixed> $o
     * @return array{ok: bool, steps: list<array{label: string, ok: ?bool, detail: string}>}
     */
    public static function run(array $o, ?string $password): array
    {
        $steps = [];
        $add = static function (string $label, ?bool $ok, string $detail = '') use (&$steps): void {
            $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $host = (string) $o['host'];
        if ($host === '') {
            $add('Instellingen', false, 'Vul eerst een SMTP-server in.');
            return ['ok' => false, 'steps' => $steps];
        }

        // 1. Servernaam
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            $add('Servernaam opzoeken', true, 'IP-adres ' . $bare);
        } else {
            $ips = @gethostbynamel($host);
            if ($ips === false || $ips === []) {
                $add('Servernaam opzoeken', false, $host . ' bestaat niet of is niet op te zoeken vanaf deze server.');
                return ['ok' => false, 'steps' => $steps];
            }
            $add('Servernaam opzoeken', true, $host . ' → ' . implode(', ', array_slice($ips, 0, 3)));
        }

        if (!class_exists(\PHPMailer\PHPMailer\SMTP::class)) {
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        }
        $smtp = new \PHPMailer\PHPMailer\SMTP();
        $smtp->Timeout = 10;
        $smtp->do_debug = 0;
        $error = static function () use ($smtp): string {
            $e = $smtp->getError();
            $text = trim(implode(' ', array_filter([(string) ($e['error'] ?? ''), (string) ($e['smtp_code'] ?? ''), (string) ($e['detail'] ?? '')])));

            return $text !== '' ? $text : 'onbekende fout';
        };

        // 2. Verbinden (+ SSL bij poort 465)
        $prefix = $o['encryption'] === 'ssl' ? 'ssl://' : '';
        $start = microtime(true);
        $connected = @$smtp->connect($prefix . $host, (int) $o['port'], 10);
        $ms = (int) round((microtime(true) - $start) * 1000);
        if (!$connected) {
            $add('Verbinden met ' . $host . ':' . (int) $o['port'], false, $error());
            return ['ok' => false, 'steps' => $steps];
        }
        $add('Verbinden met ' . $host . ':' . (int) $o['port'], true, ($o['encryption'] === 'ssl' ? 'versleuteld (SSL/TLS), ' : '') . $ms . ' ms');

        $finish = static function (bool $ok) use ($smtp, &$steps): array {
            @$smtp->quit();
            $smtp->close();

            return ['ok' => $ok, 'steps' => $steps];
        };

        // 3. Begroeting
        $helo = self::heloName();
        if (!$smtp->hello($helo)) {
            $add('Begroeting (EHLO)', false, $error());
            return $finish(false);
        }
        $add('Begroeting (EHLO)', true, 'server antwoordt');

        // 4. Versleuteling via STARTTLS
        $offersTls = (bool) $smtp->getServerExt('STARTTLS');
        if ($o['encryption'] === 'tls' || ($o['encryption'] === 'none' && $o['autotls'] && $offersTls)) {
            if (!$offersTls) {
                $add('Versleuteling (STARTTLS)', false, 'De server biedt op deze poort geen STARTTLS aan. Probeer poort 465 met SSL/TLS.');
                return $finish(false);
            }
            if (!@$smtp->startTLS()) {
                $add('Versleuteling (STARTTLS)', false, $error());
                return $finish(false);
            }
            if (!$smtp->hello($helo)) {
                $add('Versleuteling (STARTTLS)', false, 'Na STARTTLS antwoordt de server niet meer: ' . $error());
                return $finish(false);
            }
            $add('Versleuteling (STARTTLS)', true, 'verbinding versleuteld');
        } elseif ($o['encryption'] === 'none') {
            $add('Versleuteling', null, 'Uit: login en inhoud gaan onversleuteld over het netwerk.');
        }

        // 5. Inloggen
        if (!$o['auth']) {
            $add('Inloggen', null, 'Uit (de server moet mail van deze site zonder login accepteren).');
            return $finish(true);
        }
        $methods = $smtp->getServerExt('AUTH');
        if (!$methods) {
            $add('Inloggen', false, 'De server biedt (op dit moment) geen login aan.' . ($o['encryption'] === 'none' ? ' Veel servers doen dat pas na versleuteling: kies STARTTLS of SSL/TLS.' : ''));
            return $finish(false);
        }
        if ($password === null) {
            $add('Inloggen', false, 'Het opgeslagen wachtwoord is niet te ontsleutelen. Vul het opnieuw in.');
            return $finish(false);
        }
        if (!@$smtp->authenticate((string) $o['user'], $password)) {
            $add('Inloggen als ' . $o['user'], false, $error());
            return $finish(false);
        }
        $add('Inloggen als ' . $o['user'], true, 'gelukt (' . (is_array($methods) ? implode(', ', $methods) : (string) $methods) . ')');

        return $finish(true);
    }

    private static function heloName(): string
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        return $host !== '' ? $host : 'localhost';
    }
}
