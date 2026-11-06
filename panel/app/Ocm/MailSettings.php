<?php

namespace App\Ocm;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Eigen SMTP-server, in te stellen onder Instellingen → Meldingen. Staat hij aan, dan gaan alle
 * e-mails (meldingen, rapporten, wachtwoord-reset) via deze server; uit = de mailinstellingen uit
 * .env blijven gelden. Het wachtwoord staat versleuteld (APP_KEY) in de database.
 */
final class MailSettings
{
    public const KEY = 'smtp';

    /** Versleuteling: STARTTLS (meestal 587), SSL/TLS direct (465) of geen (alleen intern). */
    public const ENCRYPTION = ['starttls' => 'STARTTLS (poort 587)', 'ssl' => 'SSL/TLS (poort 465)', 'none' => 'Geen (alleen intern netwerk)'];

    /** @return array<string, mixed> */
    public static function stored(): array
    {
        try {
            return Setting::get(self::KEY);
        } catch (\Throwable) {
            return []; // tabel bestaat nog niet
        }
    }

    public static function enabled(): bool
    {
        $s = self::stored();

        return !empty($s['enabled']) && !empty($s['host']);
    }

    public static function hasPassword(): bool
    {
        return !empty(self::stored()['password']);
    }

    /** Zet de SMTP-gegevens over de config heen. Bij elke start van de app en na opslaan. */
    public static function apply(): void
    {
        if (!self::enabled()) {
            return;
        }
        $s = self::stored();
        $password = null;
        if (!empty($s['password'])) {
            try {
                $password = Crypt::decryptString((string) $s['password']);
            } catch (\Throwable) {
                $password = null;
            }
        }
        $encryption = $s['encryption'] ?? 'starttls';
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.host' => (string) $s['host'],
            'mail.mailers.smtp.port' => (int) ($s['port'] ?? 587),
            'mail.mailers.smtp.username' => ($s['username'] ?? '') !== '' ? (string) $s['username'] : null,
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.auto_tls' => $encryption !== 'none',
            // STARTTLS verplicht: nooit stilletjes onversleuteld verder als de server het niet aanbiedt.
            'mail.mailers.smtp.require_tls' => $encryption === 'starttls',
            'mail.mailers.smtp.timeout' => 15,
        ]);
        if (!empty($s['from_address'])) {
            config(['mail.from.address' => (string) $s['from_address']]);
        }
        if (!empty($s['from_name'])) {
            config(['mail.from.name' => (string) $s['from_name']]);
        }
        // Een eerder aangemaakte mailer (met de oude gegevens) weggooien.
        if (app()->resolved('mail.manager')) {
            Mail::purge('smtp');
        }
    }

    /**
     * @param array<string, mixed> $data gevalideerd
     */
    public static function save(array $data): void
    {
        $current = self::stored();
        $password = $current['password'] ?? null;
        if (!empty($data['clear_password'])) {
            $password = null;
        } elseif (isset($data['password']) && $data['password'] !== '') {
            $password = Crypt::encryptString((string) $data['password']);
        }

        Setting::put(self::KEY, [
            'enabled' => !empty($data['enabled']),
            'host' => trim((string) ($data['host'] ?? '')),
            'port' => (int) ($data['port'] ?? 587),
            'encryption' => $data['encryption'] ?? 'starttls',
            'username' => trim((string) ($data['username'] ?? '')),
            'password' => $password,
            'from_address' => trim((string) ($data['from_address'] ?? '')),
            'from_name' => trim((string) ($data['from_name'] ?? '')),
        ]);
        self::apply();
    }
}
