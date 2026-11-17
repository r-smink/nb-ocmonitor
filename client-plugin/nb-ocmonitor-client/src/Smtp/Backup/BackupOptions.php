<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Backup;

use NextBuzz\OcMonitor\Client\Smtp\Crypto;
use NextBuzz\OcMonitor\Client\Smtp\Options;

/**
 * Instellingen van de backupverbinding in de optie nb_ocm_smtp_backup (autoload uit): aan/uit plus
 * dezelfde verbindingsvelden als de primaire verbinding. Het wachtwoord staat versleuteld (Crypto).
 * De afzender komt altijd van de primaire instellingen; hier staan alleen de servergegevens.
 */
final class BackupOptions
{
    public const OPTION = 'nb_ocm_smtp_backup';
    public const FIELDS = ['host', 'port', 'encryption', 'autotls', 'auth', 'user', 'pass'];
    private const BOOLS = ['autotls', 'auth'];

    /**
     * @param mixed $value
     * @return array<string, mixed> enabled + de verbindingsvelden; pass ongewijzigd (versleuteld)
     */
    public static function normalize($value): array
    {
        $value = is_array($value) ? $value : [];
        $o = Options::normalize(array_intersect_key($value, array_flip(self::FIELDS)));

        return ['enabled' => (bool) ($value['enabled'] ?? false)] + array_intersect_key($o, array_flip(self::FIELDS));
    }

    /** @return array<string, mixed> */
    public static function get(): array
    {
        return self::normalize(get_option(self::OPTION));
    }

    /** Het wachtwoord in leesbare vorm, of null als het niet te ontsleutelen is. */
    public static function password(): ?string
    {
        return Crypto::decrypt((string) self::get()['pass']);
    }

    /** Zijn de verbindingsgegevens compleet (los van aan/uit)? */
    public static function isComplete(array $b): bool
    {
        return Options::isComplete($b) && (!$b['auth'] || $b['pass'] !== '');
    }

    /** Aan, compleet en (bij inloggen) een te ontsleutelen wachtwoord: dan kan de backup draaien. */
    public static function isUsable(?array $b = null): bool
    {
        $b = $b ?? self::get();
        if (!$b['enabled'] || !self::isComplete($b)) {
            return false;
        }

        return !$b['auth'] || Crypto::decrypt((string) $b['pass']) !== null;
    }

    /**
     * Opties voor Smtp\Mailer: de primaire instellingen (afzender, Return-Path) met de
     * verbindingsvelden van de backup erover.
     *
     * @param array<string, mixed> $backup
     * @param array<string, mixed> $primary
     * @return array<string, mixed>
     */
    public static function mailerOptions(array $backup, array $primary): array
    {
        return Options::normalize(array_merge($primary, array_intersect_key($backup, array_flip(self::FIELDS))));
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
        $from = self::get();
        $to = self::merge($from, $input, static function (string $plain): string {
            return Crypto::encrypt($plain);
        });
        update_option(self::OPTION, $to, false);

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Formulierinvoer over de opgeslagen waarden leggen (los van WordPress, voor de tests).
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $input
     * @param callable(string): string $encrypt
     * @return array<string, mixed>
     */
    public static function merge(array $stored, array $input, callable $encrypt): array
    {
        $new = $input;
        $new['enabled'] = !empty($input['enabled']);
        foreach (self::BOOLS as $bool) {
            $new[$bool] = !empty($input[$bool]);
        }
        $pass = (string) ($input['pass'] ?? '');
        if (!empty($input['clear_pass'])) {
            $new['pass'] = '';
        } elseif ($pass !== '') {
            $new['pass'] = $encrypt($pass);
        } else {
            $new['pass'] = (string) ($stored['pass'] ?? '');
        }

        return self::normalize($new);
    }

    /**
     * Omschrijving voor het wijzigingslog, zonder geheimen.
     *
     * @param array<string, mixed> $b
     */
    public static function describe(array $b): string
    {
        if ($b['host'] === '') {
            return $b['enabled'] ? 'aan, niet ingesteld' : 'uit';
        }

        return sprintf(
            '%s: %s:%d %s%s',
            $b['enabled'] ? 'aan' : 'uit',
            $b['host'],
            $b['port'],
            $b['encryption'],
            $b['auth'] ? ' als ' . $b['user'] : ' zonder login'
        );
    }
}
