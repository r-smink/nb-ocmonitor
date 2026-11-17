<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Admin;

use NextBuzz\OcMonitor\Client\Commands\SiteCommands;
use NextBuzz\OcMonitor\Client\Cron;
use NextBuzz\OcMonitor\Client\ErrorCatcher;
use NextBuzz\OcMonitor\Client\Settings;
use NextBuzz\OcMonitor\Client\Smtp\Alerts\AlertSettings;
use NextBuzz\OcMonitor\Client\Smtp\Module as SmtpModule;
use NextBuzz\OcMonitor\Client\Tweaks;

/**
 * Systeemstatus van de site. collect() verzamelt de feiten uit WordPress en PHP (zonder geheimen);
 * evaluate() is een pure functie die er controles met een oordeel (ok, warn, crit, info) van maakt,
 * zodat de drempels los te testen zijn. SupportExport gebruikt dezelfde feiten.
 */
final class SystemStatus
{
    /** Tijd van het laatste geverifieerde verzoek van het panel (RestController). */
    public const LAST_PANEL_REQUEST_OPTION = 'nb_ocm_last_panel_request';
    public const SMTP_LOG_CLEANUP_HOOK = 'nb_ocm_smtp_log_cleanup';

    private const MB = 1048576;

    /** Einde ondersteuning per PHP-minorversie (zelfde lijst als het panel, SiteIssues::PHP_EOL). */
    public const PHP_EOL = [
        '7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31',
        '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31',
    ];

    /** extensie => [ernst als ze ontbreekt, uitleg] */
    public const EXTENSIONS = [
        'sodium' => ['crit', 'Nodig voor de beveiligde koppeling met het panel.'],
        'openssl' => ['crit', 'Nodig voor HTTPS-verbindingen en versleutelde SMTP.'],
        'curl' => ['warn', 'WordPress gebruikt curl voor updates en externe verzoeken.'],
        'mbstring' => ['warn', 'Nodig voor correcte verwerking van tekens (UTF-8).'],
        'zip' => ['warn', 'Nodig voor updates en back-ups.'],
        'intl' => ['warn', 'Aanbevolen door WordPress voor taal- en datumfuncties.'],
        'image' => ['warn', 'Imagick of GD is nodig om afbeeldingen te verkleinen.'],
        'imap' => ['info', 'Nodig voor bounce-detectie (later).'],
    ];

    public const TONE_LABELS = ['ok' => 'OK', 'warn' => 'Let op', 'crit' => 'Probleem', 'info' => 'Info'];

    /**
     * Verzamelt alle feiten. Bevat bewust geen sleutels, wachtwoorden of tokens; adressen en
     * webhooks worden pas bij de export ingekort.
     *
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        global $wp_version, $wpdb;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $coreUpdate = null;
        $core = get_site_transient('update_core');
        foreach (is_object($core) && isset($core->updates) ? (array) $core->updates : [] as $offer) {
            if (is_object($offer) && ($offer->response ?? '') === 'upgrade') {
                $coreUpdate = (string) ($offer->current ?? '');
                break;
            }
        }

        $db = '';
        if (isset($wpdb) && is_object($wpdb)) {
            $db = (string) $wpdb->get_var('SELECT VERSION()');
            if ($db === '' && method_exists($wpdb, 'db_server_info')) {
                $db = (string) $wpdb->db_server_info();
            }
        }

        $uploads = wp_upload_dir(null, false);
        $uploadsDir = (string) ($uploads['basedir'] ?? '');
        $disk = null;
        if (function_exists('disk_free_space')) {
            $free = @disk_free_space(WP_CONTENT_DIR);
            $disk = $free !== false ? (int) $free : null;
        }

        $extensions = [];
        foreach (array_keys(self::EXTENSIONS) as $ext) {
            $extensions[$ext] = $ext === 'image' ? (extension_loaded('imagick') || extension_loaded('gd')) : extension_loaded($ext);
        }

        $ownHooks = [Cron::HOURLY => wp_next_scheduled(Cron::HOURLY), Cron::TWICE_DAILY => wp_next_scheduled(Cron::TWICE_DAILY)];
        $cleanup = wp_next_scheduled(self::SMTP_LOG_CLEANUP_HOOK);
        if ($cleanup) {
            $ownHooks[self::SMTP_LOG_CLEANUP_HOOK] = $cleanup;
        }
        $cron = SiteCommands::cronHealth();
        $cron['own'] = array_map(static function ($ts) {
            return $ts ? (int) $ts : null;
        }, $ownHooks);

        $summary = Settings::summary();
        $theme = wp_get_theme();
        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $plugins[] = ['file' => (string) $file, 'name' => (string) $data['Name'], 'version' => (string) $data['Version'], 'active' => is_plugin_active($file)];
        }
        $mu = [];
        foreach (get_mu_plugins() as $file => $data) {
            $mu[] = ['file' => (string) $file, 'name' => (string) $data['Name'], 'version' => (string) $data['Version']];
        }
        $alerts = AlertSettings::get();
        $errors = ErrorCatcher::list();

        return [
            'generated_at' => time(),
            'timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC',
            'abspath' => (string) ABSPATH,
            'site_url' => site_url(),
            'home_url' => home_url(),
            'admin_email' => (string) get_option('admin_email'),
            'locale' => get_locale(),
            'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
            'wp_version' => (string) $wp_version,
            'wp_update' => $coreUpdate !== '' ? $coreUpdate : null,
            'php_version' => PHP_VERSION,
            'db_server' => $db,
            'server_software' => substr(preg_replace('/[^\x20-\x7E]/', '', (string) ($_SERVER['SERVER_SOFTWARE'] ?? '')) ?? '', 0, 120),
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'wp_debug_display' => defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY,
            'wp_debug_log' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
            'timezone_string' => (string) get_option('timezone_string'),
            'gmt_offset' => (float) get_option('gmt_offset'),
            'object_cache' => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache(),
            'memory_limit' => (string) ini_get('memory_limit'),
            'wp_memory_limit' => defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : '',
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'disk_free' => $disk,
            'uploads_writable' => $uploadsDir !== '' && empty($uploads['error']) && wp_is_writable($uploadsDir),
            'extensions' => $extensions,
            'cron' => $cron,
            'paired' => Settings::isPaired(),
            'site_id' => $summary['site_id'] ?? null,
            'panel_host' => is_string($summary['panel_url'] ?? null) ? (string) wp_parse_url($summary['panel_url'], PHP_URL_HOST) : null,
            'paired_at' => $summary['paired_at'] ?? null,
            'scopes' => $summary['scopes'] ?? [],
            'last_panel_request' => ((int) get_option(self::LAST_PANEL_REQUEST_OPTION, 0)) ?: null,
            'plugin_version' => defined('NB_OCM_VERSION') ? (string) NB_OCM_VERSION : '',
            'theme' => [
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'parent' => $theme->parent() ? $theme->parent()->get('Name') . ' ' . $theme->parent()->get('Version') : null,
            ],
            'plugins' => $plugins,
            'mu_plugins' => $mu,
            'tweaks' => Tweaks::current(),
            'smtp' => SmtpModule::summary(),
            'alerts' => ['emails' => count($alerts['emails']), 'webhooks' => $alerts['webhooks'], 'events' => $alerts['events'], 'throttle' => $alerts['throttle']],
            'php_errors' => $errors['items'],
        ];
    }

    /**
     * Controles per onderdeel. Pure functie.
     *
     * @param array<string, mixed> $f feiten uit collect()
     * @return array<string, list<array{label: string, tone: string, value: string, advice: string}>>
     */
    public static function evaluate(array $f, int $now): array
    {
        return [
            'Koppeling met het panel' => self::panel($f, $now),
            'WordPress en PHP' => self::platform($f, $now),
            'Bronnen' => self::resources($f),
            'WP-cron' => self::cron($f, $now),
            'PHP-extensies' => self::extensions($f),
        ];
    }

    /**
     * @param array<string, list<array{tone: string}>> $sections
     * @return array{ok: int, warn: int, crit: int, info: int}
     */
    public static function counts(array $sections): array
    {
        $counts = ['ok' => 0, 'warn' => 0, 'crit' => 0, 'info' => 0];
        foreach ($sections as $checks) {
            foreach ($checks as $check) {
                $counts[$check['tone']]++;
            }
        }

        return $counts;
    }

    /** @return list<array{label: string, tone: string, value: string, advice: string}> */
    private static function platform(array $f, int $now): array
    {
        $checks = [];
        $wp = (string) ($f['wp_version'] ?? '');
        $update = $f['wp_update'] ?? null;
        $checks[] = $update
            ? self::check('WordPress', 'warn', $wp, 'Update naar ' . $update . ' beschikbaar. Laat Next Buzz bijwerken of doe het via Dashboard › Updates.')
            : self::check('WordPress', 'ok', $wp, 'Up-to-date.');

        $php = (string) ($f['php_version'] ?? '');
        $checks[] = self::phpCheck($php, $now);
        $checks[] = self::dbCheck((string) ($f['db_server'] ?? ''));

        $httpsHome = stripos((string) ($f['home_url'] ?? ''), 'https://') === 0;
        $httpsSite = stripos((string) ($f['site_url'] ?? ''), 'https://') === 0;
        $checks[] = $httpsHome && $httpsSite
            ? self::check('HTTPS', 'ok', 'Aan', 'Site- en home-URL gebruiken https.')
            : self::check('HTTPS', 'crit', 'Uit', 'De ' . (!$httpsHome ? 'home-URL' : 'site-URL') . ' gebruikt geen https. Zet een SSL-certificaat aan en pas de URL\'s aan onder Instellingen › Algemeen.');

        $live = ($f['environment'] ?? 'production') === 'production';
        $debug = !empty($f['wp_debug']);
        $display = $debug && !empty($f['wp_debug_display']);
        if (!$debug) {
            $checks[] = self::check('Debugmodus', 'ok', 'Uit', 'WP_DEBUG staat uit.');
        } elseif (!$live) {
            $checks[] = self::check('Debugmodus', 'info', $display ? 'Aan, fouten zichtbaar' : 'Aan', 'Geen productieomgeving (' . (string) $f['environment'] . '); debugmodus is hier prima.');
        } else {
            $checks[] = self::check('Debugmodus', 'warn', $display ? 'Aan, fouten zichtbaar' : 'Aan', $display
                ? 'Bezoekers kunnen foutmeldingen met paden zien. Zet WP_DEBUG_DISPLAY op false of WP_DEBUG uit in wp-config.php.'
                : 'WP_DEBUG staat aan op een live site. Zet hem uit als je niet actief aan het debuggen bent.');
        }

        $tz = (string) ($f['timezone_string'] ?? '');
        if ($tz !== '') {
            $checks[] = self::check('Tijdzone', 'ok', $tz, 'Ingesteld.');
        } else {
            $offset = (float) ($f['gmt_offset'] ?? 0);
            $label = 'UTC' . ($offset >= 0 ? '+' : '-') . rtrim(rtrim(number_format(abs($offset), 2, '.', ''), '0'), '.');
            $checks[] = self::check('Tijdzone', 'warn', $label, 'Kies een stad (bijv. Amsterdam) onder Instellingen › Algemeen, zodat zomer- en wintertijd en geplande taken kloppen.');
        }

        $checks[] = !empty($f['object_cache'])
            ? self::check('Object cache', 'ok', 'Aanwezig', 'Een persistente object cache (bijv. Redis) versnelt de site.')
            : self::check('Object cache', 'info', 'Niet aanwezig', 'Optioneel. Een persistente object cache (Redis of Memcached) helpt bij drukke sites en webshops.');

        return $checks;
    }

    private static function phpCheck(string $php, int $now): array
    {
        $minor = implode('.', array_slice(explode('.', $php), 0, 2));
        $eol = self::PHP_EOL[$minor] ?? (version_compare($minor, '7.4', '<') ? '2000-01-01' : null);
        if ($eol === null) {
            return self::check('PHP', 'ok', $php, 'Ondersteunde versie.');
        }
        $eolTs = (int) strtotime($eol . ' 00:00:00 UTC');
        $date = self::dutchDate($eolTs);
        if ($eolTs <= $now) {
            return self::check('PHP', 'crit', $php, 'PHP ' . $minor . ' krijgt geen beveiligingsupdates meer' . ($eol !== '2000-01-01' ? ' (sinds ' . $date . ')' : '') . '. Vraag de hoster om een nieuwere versie.');
        }
        if ($eolTs - $now < 180 * 86400) {
            return self::check('PHP', 'warn', $php, 'Ondersteuning stopt op ' . $date . '. Plan een upgrade naar een nieuwere PHP-versie.');
        }

        return self::check('PHP', 'ok', $php, 'Ondersteund tot ' . $date . '.');
    }

    private static function dbCheck(string $server): array
    {
        if ($server === '') {
            return self::check('Database', 'info', 'Onbekend', 'De versie van de databaseserver kon niet worden opgevraagd.');
        }
        $maria = stripos($server, 'mariadb') !== false;
        // "5.5.5-10.6.12-MariaDB": het echte versienummer staat achter het compat-voorvoegsel.
        $version = '';
        if ($maria && preg_match('/^5\.5\.5-(\d+\.\d+(?:\.\d+)?)/', $server, $m)) {
            $version = $m[1];
        } elseif (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $server, $m)) {
            $version = $m[1];
        }
        $label = ($maria ? 'MariaDB ' : 'MySQL ') . $version;
        $old = $version !== '' && version_compare($version, $maria ? '10.6' : '8.0', '<');

        return $old
            ? self::check('Database', 'warn', $label, 'Verouderde versie zonder beveiligingsupdates. WordPress adviseert MySQL 8.0+ of MariaDB 10.6+; vraag de hoster om een upgrade.')
            : self::check('Database', 'ok', $label, 'Ondersteunde versie.');
    }

    /** @return list<array{label: string, tone: string, value: string, advice: string}> */
    private static function resources(array $f): array
    {
        $checks = [];
        $mem = (string) ($f['memory_limit'] ?? '');
        $memBytes = self::bytes($mem);
        $wpMem = (string) ($f['wp_memory_limit'] ?? '');
        $value = $mem . ($wpMem !== '' ? ' (WP_MEMORY_LIMIT ' . $wpMem . ')' : '');
        if ($memBytes < 0) {
            $checks[] = self::check('Geheugen', 'ok', $value, 'Geen limiet ingesteld.');
        } elseif ($memBytes < 64 * self::MB) {
            $checks[] = self::check('Geheugen', 'crit', $value, 'Te weinig voor WordPress. Verhoog memory_limit naar minimaal 256M.');
        } elseif ($memBytes < 128 * self::MB) {
            $checks[] = self::check('Geheugen', 'warn', $value, 'Krap voor plugins als WooCommerce of page builders. 256M is aan te raden.');
        } else {
            $checks[] = self::check('Geheugen', 'ok', $value, 'Voldoende.');
        }

        $max = (int) ($f['max_execution_time'] ?? 0);
        if ($max === 0) {
            $checks[] = self::check('Max. uitvoertijd', 'ok', 'Onbeperkt', 'Geen limiet (gebruikelijk voor CLI of servercron).');
        } elseif ($max < 30) {
            $checks[] = self::check('Max. uitvoertijd', 'warn', $max . ' s', 'Updates en back-ups kunnen afbreken. 60 seconden of meer is aan te raden.');
        } else {
            $checks[] = self::check('Max. uitvoertijd', 'ok', $max . ' s', 'Voldoende.');
        }

        $upload = (string) ($f['upload_max_filesize'] ?? '');
        $post = (string) ($f['post_max_size'] ?? '');
        $u = self::bytes($upload);
        $p = self::bytes($post);
        $value = $upload . ' / ' . $post;
        if ($p > 0 && $u > $p) {
            $checks[] = self::check('Uploadlimiet', 'warn', $value, 'post_max_size is kleiner dan upload_max_filesize; grote uploads mislukken dan zonder duidelijke melding.');
        } elseif ($u > 0 && $u < 8 * self::MB) {
            $checks[] = self::check('Uploadlimiet', 'warn', $value, 'Laag: afbeeldingen en PDF\'s van een paar MB worden geweigerd. 32M of meer is gebruikelijk.');
        } else {
            $checks[] = self::check('Uploadlimiet', 'ok', $value, 'upload_max_filesize / post_max_size.');
        }

        $disk = $f['disk_free'] ?? null;
        if ($disk === null) {
            $checks[] = self::check('Vrije schijfruimte', 'info', 'Onbekend', 'De hoster staat het opvragen van de vrije ruimte niet toe.');
        } elseif ($disk < 200 * self::MB) {
            $checks[] = self::check('Vrije schijfruimte', 'crit', self::size((int) $disk), 'Bijna vol: uploads, updates en back-ups gaan mislukken. Ruim op of vraag meer ruimte aan.');
        } elseif ($disk < 1024 * self::MB) {
            $checks[] = self::check('Vrije schijfruimte', 'warn', self::size((int) $disk), 'Minder dan 1 GB vrij. Houd ruimte over voor updates en back-ups.');
        } else {
            $checks[] = self::check('Vrije schijfruimte', 'ok', self::size((int) $disk), 'Voldoende ruimte op de schijf van wp-content.');
        }

        $checks[] = !empty($f['uploads_writable'])
            ? self::check('Uploadmap', 'ok', 'Schrijfbaar', 'WordPress kan bestanden opslaan.')
            : self::check('Uploadmap', 'crit', 'Niet schrijfbaar', 'Uploads en updates mislukken. Controleer de rechten van wp-content/uploads.');

        return $checks;
    }

    /** @return list<array{label: string, tone: string, value: string, advice: string}> */
    private static function extensions(array $f): array
    {
        $loaded = (array) ($f['extensions'] ?? []);
        $checks = [];
        foreach (self::EXTENSIONS as $ext => [$severity, $why]) {
            $label = $ext === 'image' ? 'imagick of gd' : $ext;
            if (!empty($loaded[$ext])) {
                $checks[] = self::check($label, $severity === 'info' ? 'info' : 'ok', 'Aanwezig', $why);
            } else {
                $checks[] = self::check($label, $severity, 'Ontbreekt', $why . ($severity === 'info' ? '' : ' Vraag de hoster om de extensie aan te zetten.'));
            }
        }

        return $checks;
    }

    /** @return list<array{label: string, tone: string, value: string, advice: string}> */
    private static function cron(array $f, int $now): array
    {
        $cron = (array) ($f['cron'] ?? []);
        $checks = [];
        $checks[] = !empty($cron['disabled'])
            ? self::check('DISABLE_WP_CRON', 'info', 'Aan', 'WordPress start cron niet zelf; er moet een echte servercron draaien (bijv. elke 5 minuten wp-cron.php).')
            : self::check('DISABLE_WP_CRON', 'ok', 'Uit', 'Cron start bij bezoek aan de site.');

        $overdue = (int) ($cron['overdue'] ?? 0);
        $age = isset($cron['oldest_overdue_seconds']) ? (int) $cron['oldest_overdue_seconds'] : 0;
        if ($overdue === 0) {
            $checks[] = self::check('Achterstallige taken', 'ok', 'Geen', 'Geplande taken lopen op tijd.');
        } else {
            $value = $overdue . ' (oudste ' . self::duration($age) . ' te laat)';
            $tone = $age > 86400 ? 'crit' : ($age > 3600 ? 'warn' : 'ok');
            $advice = $tone === 'ok'
                ? 'Kleine vertraging; cron draait bij het volgende bezoek.'
                : (!empty($cron['disabled'])
                    ? 'De servercron lijkt niet te draaien. Controleer de cronjob bij de hoster.'
                    : 'Cron draait niet of nauwelijks. Stel een servercron in of controleer of wp-cron.php bereikbaar is.');
            $checks[] = self::check('Achterstallige taken', $tone, $value, $advice);
        }

        $labels = [Cron::HOURLY => 'Taak elk uur', Cron::TWICE_DAILY => 'Taak 2x per dag', self::SMTP_LOG_CLEANUP_HOOK => 'Opschonen e-maillog'];
        foreach ((array) ($cron['own'] ?? []) as $hook => $next) {
            $label = $labels[$hook] ?? (string) $hook;
            if ($next === null) {
                $checks[] = self::check($label, 'warn', 'Niet ingepland', 'Zet de plugin uit en weer aan om de taak opnieuw in te plannen (' . $hook . ').');
            } elseif ($next < $now - 3600) {
                $checks[] = self::check($label, 'warn', self::duration($now - (int) $next) . ' te laat', 'De taak ' . $hook . ' had al moeten draaien.');
            } else {
                $checks[] = self::check($label, 'ok', $next <= $now ? 'Nu' : 'Over ' . self::duration((int) $next - $now), $hook);
            }
        }

        $checks[] = !empty($cron['alternate'])
            ? self::check('ALTERNATE_WP_CRON', 'info', 'Aan', 'Cron via een doorverwijzing; alleen nodig als de server zichzelf niet kan bereiken.')
            : self::check('ALTERNATE_WP_CRON', 'ok', 'Uit', 'Standaard.');

        return $checks;
    }

    /** @return list<array{label: string, tone: string, value: string, advice: string}> */
    private static function panel(array $f, int $now): array
    {
        $checks = [];
        $paired = !empty($f['paired']);
        $checks[] = $paired
            ? self::check('Gekoppeld', 'ok', 'Ja', 'Site-ID ' . (string) ($f['site_id'] ?? ''))
            : self::check('Gekoppeld', 'warn', 'Nee', 'Het panel kan deze site niet monitoren of beheren. Koppel de site via de tab Overzicht.');

        $last = isset($f['last_panel_request']) ? (int) $f['last_panel_request'] : 0;
        if ($last <= 0) {
            $checks[] = self::check('Laatste contact', $paired ? 'warn' : 'info', 'Nog niet', $paired ? 'Nog geen geverifieerd verzoek van het panel ontvangen (sinds deze versie). Controleer of de site in het panel actief is.' : 'Pas na het koppelen.');
        } else {
            $age = max(0, $now - $last);
            $value = self::duration($age) . ' geleden';
            if ($age > 72 * 3600) {
                $checks[] = self::check('Laatste contact', 'crit', $value, 'Het panel heeft al dagen geen contact. Mogelijk blokkeert een firewall of beveiligingsplugin de REST API (nb-ocm/v1).');
            } elseif ($age > 24 * 3600) {
                $checks[] = self::check('Laatste contact', 'warn', $value, 'Langer dan een dag geen contact met het panel. Controleer of de REST API bereikbaar is.');
            } else {
                $checks[] = self::check('Laatste contact', 'ok', $value, 'Het panel bereikt de site.');
            }
        }

        $recent = self::recentErrors((array) ($f['php_errors'] ?? []), $now);
        $checks[] = $recent === 0
            ? self::check('PHP-fouten (24 uur)', 'ok', 'Geen', 'Geen fatale PHP-fouten in het afgelopen etmaal.')
            : self::check('PHP-fouten (24 uur)', 'warn', (string) $recent, 'Fatale fouten opgevangen; Next Buzz ziet ze in het panel. Details staan in de support-export.');

        return $checks;
    }

    /** Aantal unieke fatale fouten dat in de laatste 24 uur (nog) is voorgekomen. */
    public static function recentErrors(array $items, int $now): int
    {
        $n = 0;
        foreach ($items as $item) {
            if (is_array($item) && (int) ($item['last_seen'] ?? 0) >= $now - 86400) {
                $n++;
            }
        }

        return $n;
    }

    /** @return array{label: string, tone: string, value: string, advice: string} */
    private static function check(string $label, string $tone, string $value, string $advice): array
    {
        return ['label' => $label, 'tone' => $tone, 'value' => $value, 'advice' => $advice];
    }

    /** "256M", "1G", "-1" naar bytes (-1 = onbeperkt). */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return $value === '-1' ? -1 : 0;
        }
        $unit = strtolower(substr($value, -1));
        $n = (int) $value;
        switch ($unit) {
            case 'g':
                return $n * 1073741824;
            case 'm':
                return $n * self::MB;
            case 'k':
                return $n * 1024;
            default:
                return $n;
        }
    }

    public static function size(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
        }

        return number_format($bytes / self::MB, 0, ',', '.') . ' MB';
    }

    /** Duur in het Nederlands, grof afgerond: "45 s", "12 min", "3 uur", "2 dagen". */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds < 3600) {
            return (int) floor($seconds / 60) . ' min';
        }
        if ($seconds < 172800) {
            return (int) floor($seconds / 3600) . ' uur';
        }
        $days = (int) floor($seconds / 86400);

        return $days . ' dagen';
    }

    private static function dutchDate(int $ts): string
    {
        $months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

        return (int) gmdate('j', $ts) . ' ' . $months[(int) gmdate('n', $ts) - 1] . ' ' . gmdate('Y', $ts);
    }
}
