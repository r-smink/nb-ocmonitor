<?php

namespace App\Ocm;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Algemene instellingen van de tool, in te stellen op de pagina Instellingen. Ze overschrijven
 * de waarden uit .env/config; leeg laten = de waarde uit .env blijft gelden. Geheimen (API-sleutels)
 * staan versleuteld in de database. Sleutels van de signer, de database en Spaces blijven bewust
 * alleen in .env.
 */
final class AppSettings
{
    public const KEY = 'app_settings';

    /**
     * key => [label, type (text|int|float|bool|secret), config-pad, min, max]
     */
    public const FIELDS = [
        'app_name' => ['Naam van de tool (linksboven)', 'text', null, null, 40],
        'heartbeat_minutes' => ['Heartbeat: elke zoveel minuten contact met de sites', 'int', 'ocm.heartbeat_minutes', 5, 120],
        'pagespeed_api_key' => ['PageSpeed Insights API-sleutel', 'secret', 'ocm.pagespeed.api_key', null, null],
        'pagespeed_interval_days' => ['PageSpeed: elke zoveel dagen meten', 'int', 'ocm.pagespeed.interval_days', 1, 60],
        'pagespeed_drop' => ['PageSpeed: melding bij een daling van (punten)', 'int', 'ocm.pagespeed.drop_alert_points', 1, 50],
        'wordfence_api_key' => ['Wordfence Intelligence API-sleutel', 'secret', 'ocm.vulnerabilities.api_key', null, null],
        'ssl_warn_days' => ['SSL: waarschuwen zoveel dagen voor het verlopen', 'int', 'ocm.monitoring.ssl_warn_days', 1, 90],
        'domain_warn_days' => ['Domein: waarschuwen zoveel dagen voor het verlopen', 'int', 'ocm.monitoring.domain_warn_days', 1, 180],
        'visual_enabled' => ['Visuele controle bij updates', 'bool', 'ocm.visual.enabled', null, null],
        'visual_threshold' => ['Visuele controle: melding vanaf (% veranderd)', 'float', 'ocm.visual.threshold_percent', 1, 90],
        'backups_enabled' => ['Backup vóór updates', 'bool', 'ocm.backups.enabled', null, null],
        'backup_keep_days' => ['Backups bewaren (dagen)', 'int', 'ocm.backups.keep_days', 1, 30],
        'backup_full_reuse_hours' => ['Na een volledige backup: zoveel uur geen losse backups per update', 'int', 'ocm.backups.full_reuse_hours', 0, 48],
        'alerts_enabled' => ['Meldingen versturen', 'bool', 'ocm.alerts.enabled', null, null],
        'alert_delay_minutes' => ['Meldingen: pas melden na zoveel minuten (tegen korte haperingen)', 'int', 'ocm.alerts.delay_minutes', 0, 120],
        'alert_repeat_hours' => ['Meldingen: kritiek opnieuw melden na zoveel uur (0 = nooit)', 'int', 'ocm.alerts.repeat_hours', 0, 168],
        'update_chunk' => ['Updates: onderdelen per opdracht', 'int', 'ocm.updates.chunk_size', 1, 10],
        'widget_contact' => ['Dashboardwidget op de sites: tekst onderaan (bijv. contactgegevens)', 'text', null, null, 300],
        'links_interval_days' => ['Kapotte links: elke zoveel dagen controleren', 'int', 'ocm.links.interval_days', 1, 60],
        'google_client_id' => ['Google OAuth-client-ID', 'text', 'ocm.google.client_id', null, 200],
        'google_client_secret' => ['Google OAuth-clientgeheim', 'secret', 'ocm.google.client_secret', null, null],
        'anthropic_api_key' => ['Claude API-sleutel (Anthropic)', 'secret', 'ocm.ai.api_key', null, null],
        'ai_changelog' => ['Claude: changelogs van plugin-updates samenvatten en het risico inschatten', 'bool', 'ocm.ai.changelog', null, null],
        'ai_report' => ['Claude: samenvatting in gewone taal bovenaan het rapport', 'bool', 'ocm.ai.report', null, null],
    ];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return mixed */
    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$cache === null) {
            try {
                self::$cache = Setting::get(self::KEY);
            } catch (\Throwable) {
                self::$cache = []; // tabel bestaat nog niet (eerste migratie, tests)
            }
        }

        return self::$cache;
    }

    /** Zet de opgeslagen waarden over de config heen. Wordt bij elke start van de app aangeroepen. */
    public static function apply(): void
    {
        self::$cache = null;
        $all = self::all();
        foreach (self::FIELDS as $key => [, $type, $path]) {
            if ($path === null || !array_key_exists($key, $all) || $all[$key] === null || $all[$key] === '') {
                continue;
            }
            $value = $all[$key];
            if ($type === 'secret') {
                try {
                    $value = Crypt::decryptString((string) $value);
                } catch (\Throwable) {
                    continue;
                }
            }
            config([$path => $value]);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $menu key => state
     */
    public static function save(array $input, array $menu, array $clearSecrets = []): void
    {
        $current = self::all();
        $out = ['menu' => []];
        foreach (self::FIELDS as $key => [, $type, , $min, $max]) {
            $value = $input[$key] ?? null;
            $out[$key] = match ($type) {
                'bool' => in_array($value, ['0', '1', 0, 1, true, false], true) ? (bool) (int) $value : null,
                'int' => is_numeric($value) ? max($min, min($max, (int) $value)) : null,
                'float' => is_numeric($value) ? max($min, min($max, (float) $value)) : null,
                'secret' => in_array($key, $clearSecrets, true) ? null
                    : (is_string($value) && trim($value) !== '' ? Crypt::encryptString(trim($value)) : ($current[$key] ?? null)),
                default => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, (int) $max) : null,
            };
        }
        foreach (Menu::ITEMS as $key => $item) {
            if (!$item[3] && isset(Menu::STATES[$menu[$key] ?? ''])) {
                $out['menu'][$key] = $menu[$key];
            }
        }
        Setting::put(self::KEY, $out);
        self::apply();
    }

    public static function hasSecret(string $key): bool
    {
        return !empty(self::all()[$key]);
    }
}
