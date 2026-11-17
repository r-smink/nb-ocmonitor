<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Admin;

/**
 * Support-export: een leesbaar tekstrapport over de site om naar Next Buzz te sturen.
 *
 * build() is een pure functie over de feiten van SystemStatus::collect(). Ze neemt alleen velden
 * over die op een vaste lijst staan (nooit sleutels, wachtwoorden, salts, tokens of cookies) en
 * haalt de tekst daarna nog door scrub(): e-mailadressen ingekort (i***@domein.nl), querystrings
 * uit URL's, lange sleutelachtige reeksen en het ABSPATH weg. Webhooks alleen als host.
 */
final class SupportExport
{
    private const MAX_ERRORS = 20;
    private const MAX_MESSAGE = 200;

    /** @param array<string, mixed> $f */
    public static function build(array $f): string
    {
        $now = (int) ($f['generated_at'] ?? 0);
        $out = [];
        $out[] = 'NB OC Monitor - support-export';
        $out[] = 'Gegenereerd: ' . self::when($now, (string) ($f['timezone'] ?? 'UTC'));
        $out[] = 'Deel dit bestand alleen met Next Buzz. Het bevat geen wachtwoorden of sleutels.';

        self::section($out, 'Site');
        self::row($out, 'Site-URL', self::url((string) ($f['site_url'] ?? '')));
        self::row($out, 'Home-URL', self::url((string) ($f['home_url'] ?? '')));
        self::row($out, 'Beheerdersmail', self::maskEmail((string) ($f['admin_email'] ?? '')));
        self::row($out, 'Taal', (string) ($f['locale'] ?? ''));
        self::row($out, 'Omgeving', (string) ($f['environment'] ?? ''));

        self::section($out, 'Versies');
        self::row($out, 'WordPress', (string) ($f['wp_version'] ?? '') . (!empty($f['wp_update']) ? ' (update naar ' . (string) $f['wp_update'] . ' beschikbaar)' : ''));
        self::row($out, 'PHP', (string) ($f['php_version'] ?? ''));
        self::row($out, 'Database', (string) ($f['db_server'] ?? ''));
        self::row($out, 'Webserver', (string) ($f['server_software'] ?? ''));
        $theme = (array) ($f['theme'] ?? []);
        self::row($out, 'Actief thema', trim((string) ($theme['name'] ?? '') . ' ' . (string) ($theme['version'] ?? '')) . (!empty($theme['parent']) ? ' (child van ' . (string) $theme['parent'] . ')' : ''));

        $plugins = (array) ($f['plugins'] ?? []);
        $active = count(array_filter($plugins, static function ($p): bool {
            return is_array($p) && !empty($p['active']);
        }));
        self::section($out, 'Plugins (' . count($plugins) . ', waarvan ' . $active . ' actief)');
        foreach ($plugins as $p) {
            if (is_array($p)) {
                $out[] = sprintf('[%s] %s %s (%s)', !empty($p['active']) ? 'actief' : 'uit   ', (string) ($p['name'] ?? ''), (string) ($p['version'] ?? ''), (string) ($p['file'] ?? ''));
            }
        }
        if ($plugins === []) {
            $out[] = '(geen)';
        }
        self::section($out, 'Must-use plugins');
        foreach ((array) ($f['mu_plugins'] ?? []) as $p) {
            if (is_array($p)) {
                $out[] = sprintf('%s %s (%s)', (string) ($p['name'] ?? ''), (string) ($p['version'] ?? ''), (string) ($p['file'] ?? ''));
            }
        }
        if (empty($f['mu_plugins'])) {
            $out[] = '(geen)';
        }

        self::section($out, 'OC Monitor');
        self::row($out, 'Versie', (string) ($f['plugin_version'] ?? ''));
        self::row($out, 'Gekoppeld', !empty($f['paired']) ? 'ja' : 'nee');
        if (!empty($f['paired'])) {
            self::row($out, 'Site-ID', (string) ($f['site_id'] ?? ''));
            self::row($out, 'Panel', (string) ($f['panel_host'] ?? ''));
            self::row($out, 'Gekoppeld op', !empty($f['paired_at']) ? self::when((int) $f['paired_at'], (string) ($f['timezone'] ?? 'UTC')) : '-');
            self::row($out, 'Scopes', implode(', ', array_map('strval', (array) ($f['scopes'] ?? []))));
        }
        self::row($out, 'Laatste verzoek panel', !empty($f['last_panel_request']) ? self::when((int) $f['last_panel_request'], (string) ($f['timezone'] ?? 'UTC')) : 'nog niet');
        $tweaks = [];
        foreach ((array) ($f['tweaks'] ?? []) as $key => $value) {
            $tweaks[] = $key . '=' . (is_bool($value) ? ($value ? 'aan' : 'uit') : (string) (is_scalar($value) ? $value : ''));
        }
        self::row($out, 'Tweaks', $tweaks !== [] ? implode(', ', $tweaks) : '-');

        self::section($out, 'SMTP');
        $smtp = (array) ($f['smtp'] ?? []);
        if (empty($smtp['enabled'])) {
            $out[] = 'Module uit';
        } else {
            self::row($out, 'Actief', !empty($smtp['active']) ? 'ja' : 'nee' . (!empty($smtp['reason']) ? ' - ' . (string) $smtp['reason'] : ''));
            self::row($out, 'Server', (string) ($smtp['host'] ?? '') . ':' . (string) ($smtp['port'] ?? ''));
            self::row($out, 'Versleuteling', (string) ($smtp['encryption'] ?? ''));
            self::row($out, 'Afzender', self::maskEmail((string) ($smtp['from_email'] ?? '')));
            self::row($out, 'Andere mailplugins', implode(', ', array_map('strval', (array) ($smtp['conflicts'] ?? []))) ?: '-');
            $test = is_array($smtp['last_test'] ?? null) ? $smtp['last_test'] : null;
            self::row($out, 'Laatste testmail', $test === null ? '-' : (!empty($test['ok']) ? 'gelukt' : 'mislukt: ' . self::cut(self::scrub((string) ($test['error'] ?? '')), self::MAX_MESSAGE))
                . ($test !== null && !empty($test['time']) ? ' (' . self::when((int) $test['time'], (string) ($f['timezone'] ?? 'UTC')) . ')' : ''));
        }

        self::section($out, 'Meldingen');
        $alerts = (array) ($f['alerts'] ?? []);
        $hosts = [];
        foreach ((array) ($alerts['webhooks'] ?? []) as $hook) {
            $host = parse_url((string) $hook, PHP_URL_HOST);
            $hosts[] = is_string($host) ? $host : '(ongeldig)';
        }
        $emails = $alerts['emails'] ?? 0;
        self::row($out, 'E-mailontvangers', (string) (is_array($emails) ? count($emails) : (int) $emails));
        self::row($out, 'Webhooks', count($hosts) . ($hosts !== [] ? ' (' . implode(', ', $hosts) . ')' : ''));
        $events = [];
        foreach ((array) ($alerts['events'] ?? []) as $type => $on) {
            $events[] = $type . '=' . ($on ? 'aan' : 'uit');
        }
        self::row($out, 'Gebeurtenissen', $events !== [] ? implode(', ', $events) : '-');
        self::row($out, 'Maximaal 1 melding per', (int) ($alerts['throttle'] ?? 0) . ' min');

        self::section($out, 'WP-cron');
        $cron = (array) ($f['cron'] ?? []);
        self::row($out, 'DISABLE_WP_CRON', !empty($cron['disabled']) ? 'aan' : 'uit');
        self::row($out, 'ALTERNATE_WP_CRON', !empty($cron['alternate']) ? 'aan' : 'uit');
        self::row($out, 'Geplande taken', (string) (int) ($cron['events'] ?? 0));
        self::row($out, 'Achterstallig', (int) ($cron['overdue'] ?? 0) . (isset($cron['oldest_overdue_seconds']) ? ' (oudste ' . SystemStatus::duration((int) $cron['oldest_overdue_seconds']) . ' te laat)' : ''));
        foreach ((array) ($cron['own'] ?? []) as $hook => $next) {
            self::row($out, (string) $hook, $next ? self::when((int) $next, (string) ($f['timezone'] ?? 'UTC')) : 'niet ingepland');
        }

        self::section($out, 'Systeemcontroles');
        foreach (SystemStatus::evaluate($f, $now) as $title => $checks) {
            $out[] = '-- ' . $title;
            foreach ($checks as $c) {
                $out[] = sprintf('[%s] %s: %s - %s', SystemStatus::TONE_LABELS[$c['tone']] ?? $c['tone'], $c['label'], $c['value'], $c['advice']);
            }
        }

        $errors = array_values(array_filter((array) ($f['php_errors'] ?? []), 'is_array'));
        self::section($out, 'Recente PHP-fouten (' . count($errors) . ')');
        foreach (array_slice($errors, 0, self::MAX_ERRORS) as $e) {
            $out[] = sprintf(
                '%s | %dx | %s: %s in %s:%d (%s)',
                !empty($e['last_seen']) ? self::when((int) $e['last_seen'], (string) ($f['timezone'] ?? 'UTC')) : '-',
                (int) ($e['count'] ?? 1),
                (string) ($e['type'] ?? 'Error'),
                self::cut(self::scrub((string) ($e['message'] ?? ''), (string) ($f['abspath'] ?? '')), self::MAX_MESSAGE),
                self::relative((string) ($e['file'] ?? ''), (string) ($f['abspath'] ?? '')),
                (int) ($e['line'] ?? 0),
                (string) parse_url((string) ($e['url'] ?? '/'), PHP_URL_PATH)
            );
        }
        if ($errors === []) {
            $out[] = '(geen)';
        }

        return self::scrub(implode("\n", $out) . "\n", (string) ($f['abspath'] ?? ''));
    }

    /**
     * Vangnet over vrije tekst (eerst per foutmelding, vóór het inkorten, en daarna over het hele
     * rapport): e-mailadressen inkorten, querystrings en fragmenten uit
     * URL's, lange sleutelachtige reeksen weg, ABSPATH weg.
     */
    public static function scrub(string $text, string $abspath = ''): string
    {
        $text = self::relative($text, $abspath);
        $text = (string) preg_replace_callback('/[A-Za-z0-9._%+\-]+@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', static function (array $m): string {
            return self::maskEmail($m[0]);
        }, $text);
        $text = (string) preg_replace('~(\b[a-z][a-z0-9+.\-]*://[^\s?#"\'<>]*)[?#][^\s"\'<>]*~i', '$1?[weggelaten]', $text);
        // Base64/hex-achtige reeksen van 32+ tekens met cijfers en letters: sleutels, tokens, hashes.
        $text = (string) preg_replace_callback('/[A-Za-z0-9_\-+=]{32,}/', static function (array $m): string {
            $s = $m[0];

            return preg_match('/\d/', $s) && preg_match('/[A-Za-z]/', $s) ? '[weggelaten]' : $s;
        }, $text);

        return $text;
    }

    /** "info@klant.nl" -> "i***@klant.nl". */
    public static function maskEmail(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return $email === '' ? '-' : '***';
        }

        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /** Bestandsnaam voor de download. */
    public static function filename(string $homeUrl, int $now): string
    {
        $host = (string) parse_url($homeUrl, PHP_URL_HOST);
        $host = preg_replace('/[^a-z0-9.\-]+/', '-', strtolower($host)) ?: 'site';

        return 'support-' . $host . '-' . gmdate('Ymd-Hi', $now) . '.txt';
    }

    /** @param list<string> $out */
    private static function section(array &$out, string $title): void
    {
        $out[] = '';
        $out[] = '== ' . $title . ' ==';
    }

    /** @param list<string> $out */
    private static function row(array &$out, string $label, string $value): void
    {
        $out[] = $label . ': ' . ($value !== '' ? $value : '-');
    }

    private static function url(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        return ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
    }

    private static function relative(string $text, string $abspath): string
    {
        $root = rtrim($abspath, '/\\');
        if ($root === '') {
            return $text;
        }

        return str_replace([$root . '/', $root . '\\', str_replace('\\', '/', $root) . '/'], '', $text);
    }

    private static function cut(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($len <= $max) {
            return $text;
        }

        return (function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max)) . '…';
    }

    private static function when(int $ts, string $timezone): string
    {
        if ($ts <= 0) {
            return '-';
        }
        try {
            $tz = new \DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i T');
    }
}
