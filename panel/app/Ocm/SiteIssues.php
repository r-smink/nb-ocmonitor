<?php

namespace App\Ocm;

use App\Models\PagespeedResult;
use App\Models\Site;
use App\Models\SiteEvent;
use Illuminate\Support\Collection;

/**
 * Bepaalt per site de aandachtspunten voor het overzicht, de tegels en het sitedetail.
 * Alles komt uit gegevens die het panel al heeft: status, inventaris, events en PageSpeed.
 *
 * Een issue: ['level' => crit|warn|info, 'category' => sleutel uit CATEGORIES, 'text' => string].
 */
final class SiteIssues
{
    public const CATEGORIES = [
        'beschikbaarheid' => 'Beschikbaarheid',
        'beveiliging' => 'Beveiliging',
        'updates' => 'Updates',
        'prestaties' => 'Prestaties',
        'koppeling' => 'Koppeling',
    ];

    public const LEVEL_RANK = ['crit' => 0, 'warn' => 1, 'info' => 2];

    /** @var array<string, \App\Models\PluginDirectoryEntry>|null gegevens van wordpress.org, per load() opnieuw */
    private static ?array $directory = null;

    /** Hoe ver terug events meetellen (nieuwe beheerders, gewijzigde site-URL e.d.). */
    public const EVENT_WINDOW_DAYS = 14;

    /** Einde van de beveiligingsupdates per PHP-versie (php.net/supported-versions). */
    /** Einde van de beveiligingsupdates voor een PHP-versie (bijv. "8.1"), of null als onbekend. */
    public static function phpEol(string $minor): ?string
    {
        return self::PHP_EOL[$minor] ?? (version_compare($minor, '7.4', '<') ? '2000-01-01' : null);
    }

    private const PHP_EOL = [
        '7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31',
        '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31',
    ];

    private const EVENT_TYPES = [
        'user.created', 'user.role_changed', 'option.changed', 'component.changed_externally', 'login.failed_summary',
        'plugin.update', 'theme.update', 'plugin.rolled_back', 'theme.rolled_back',
    ];

    private const PRIVILEGED_ROLES = ['administrator', 'editor', 'author', 'shop_manager'];

    /** Bronnen waarbij een nieuwe beheerder waarschijnlijk bewust is aangemaakt. */
    private const TRUSTED_SOURCES = ['wp-admin', 'wp-cli', 'nb-ocm'];

    /**
     * Berekent de issues voor alle sites in een paar queries en zet ze op de modellen
     * (zodat $site->issues() en $site->level() geen eigen queries meer doen).
     *
     * @param Collection<int, Site> $sites
     */
    public static function load(Collection $sites): void
    {
        self::$directory = PluginDirectory::all();
        $paired = $sites->filter(fn (Site $s) => $s->isPaired());
        $ids = $paired->pluck('id')->all();
        $events = collect();
        $pagespeed = collect();
        $visual = collect();
        $vulns = Vulnerabilities::index(array_keys(Vulnerabilities::componentKeys($paired)));
        if ($ids !== []) {
            $visual = \App\Models\VisualCheck::query()->whereIn('site_id', $ids)->where('status', 'done')->whereNull('reviewed_at')
                ->where('after_at', '>=', now()->subDays(7))->orderByDesc('id')
                ->get(['id', 'site_id', 'status', 'diff_percent', 'after_at', 'reviewed_at'])->unique('site_id')->keyBy('site_id');
            $events = SiteEvent::query()->whereIn('site_id', $ids)
                ->where('occurred_at', '>=', now()->subDays(self::EVENT_WINDOW_DAYS))
                ->whereIn('type', self::EVENT_TYPES)
                ->orderBy('occurred_at')->orderBy('seq')
                ->get()->groupBy('site_id');
            $pagespeed = PagespeedResult::query()->whereIn('site_id', $ids)
                ->where('strategy', 'mobile')->whereNull('error')->whereNotNull('performance')
                ->where('measured_at', '>=', now()->subDays(90))
                ->orderByDesc('measured_at')
                ->get()->groupBy('site_id');
        }

        foreach ($sites as $site) {
            $site->setIssues(self::forSite($site, $events->get($site->id, collect()), $pagespeed->get($site->id, collect()), $visual->get($site->id), $vulns));
        }
    }

    /**
     * @param Collection<int, SiteEvent> $events oplopend in tijd
     * @param Collection<int, PagespeedResult> $pagespeed mobiel, nieuwste eerst
     * @return list<array{level: string, category: string, text: string}>
     */
    public static function forSite(Site $site, Collection $events, Collection $pagespeed, ?\App\Models\VisualCheck $visual = null, ?Collection $vulns = null): array
    {
        if (!$site->isPaired()) {
            return [['level' => 'info', 'category' => 'koppeling', 'text' => $site->pairingIsOpen() ? 'Wacht op koppeling' : 'Koppelcode verlopen']];
        }

        $issues = [];
        // $extra: optionele aanwijzingen voor een actieknop (updates, component, visual, pagespeed).
        $add = function (string $level, string $category, string $text, array $extra = []) use (&$issues): void {
            $issues[] = ['level' => $level, 'category' => $category, 'text' => $text] + $extra + ['key' => self::key($category, $text)];
        };

        // Beschikbaarheid en koppeling
        if ($site->status === 'unreachable') {
            $add('crit', 'beschikbaarheid', 'Niet bereikbaar');
        } elseif ($site->status === 'error') {
            $add('crit', 'beschikbaarheid', 'Fout bij laatste contact');
        }
        $heartbeat = (int) config('ocm.heartbeat_minutes') * 3;
        if ($site->last_seen_at === null || $site->last_seen_at->lt(now()->subMinutes($heartbeat))) {
            $add('warn', 'beschikbaarheid', 'Geen heartbeat sinds ' . ($site->last_seen_at?->diffForHumans() ?? 'koppeling'), ['key' => 'heartbeat']);
        }
        if ($site->uptime_state === 'down') {
            $add('crit', 'beschikbaarheid', 'Website offline' . ($site->down_since ? ' sinds ' . $site->down_since->timezone('Europe/Amsterdam')->format('d-m H:i') : ''), ['key' => 'offline']);
        } elseif ($site->response_ms !== null && $site->response_ms > 4000) {
            $add('info', 'prestaties', 'Trage homepage: ' . number_format($site->response_ms / 1000, 1, ',', '') . ' s', ['key' => 'slow']);
        }
        $sslWarn = (int) config('ocm.monitoring.ssl_warn_days', 14);
        if ($site->ssl_expires_at !== null) {
            $days = (int) floor(now()->diffInDays($site->ssl_expires_at, false));
            if ($days < 0) {
                $add('crit', 'beveiliging', 'SSL-certificaat is verlopen (' . $site->ssl_expires_at->format('d-m-Y') . ')', ['key' => 'ssl-expiry']);
            } elseif ($days <= 3) {
                $add('crit', 'beveiliging', "SSL-certificaat verloopt over {$days} dag(en)", ['key' => 'ssl-expiry']);
            } elseif ($days <= $sslWarn) {
                $add('warn', 'beveiliging', "SSL-certificaat verloopt over {$days} dagen" . ($site->ssl_issuer ? " ({$site->ssl_issuer})" : ''), ['key' => 'ssl-expiry']);
            }
        }
        if ($site->ssl_error !== null && str_starts_with($site->url, 'https://')) {
            $add('crit', 'beveiliging', $site->ssl_error, ['key' => 'ssl-error']);
        }
        if ($site->domain_expires_at !== null) {
            $days = (int) floor(now()->diffInDays($site->domain_expires_at, false));
            if ($days < 0) {
                $add('crit', 'beschikbaarheid', 'Domeinregistratie is verlopen', ['key' => 'domain-expiry']);
            } elseif ($days <= (int) config('ocm.monitoring.domain_warn_days', 30)) {
                $add('warn', 'beschikbaarheid', "Domein verloopt over {$days} dagen (" . $site->domain_expires_at->format('d-m-Y') . ')', ['key' => 'domain-expiry']);
            }
        }
        // Kwetsbaarheden (Wordfence Intelligence), één melding per onderdeel met de ernstigste bovenaan.
        $rank = ['crit' => 0, 'warn' => 1, 'info' => 2];
        foreach (collect(Vulnerabilities::forSite($site, $vulns))->groupBy(fn ($m) => $m['component']['type'] . ':' . $m['component']['slug']) as $matches) {
            $worst = $matches->sortBy(fn ($m) => $rank[$m['vulnerability']->level()] . str_pad((string) (100 - (int) (($m['vulnerability']->cvss_score ?? 0) * 10)), 3, '0', STR_PAD_LEFT))->first();
            $c = $worst['component'];
            $v = $worst['vulnerability'];
            $fixed = $matches->pluck('fixed')->filter()->sort('version_compare')->last();
            $text = ($matches->count() === 1 ? 'Kwetsbaarheid' : $matches->count() . ' kwetsbaarheden') . " in {$c['name']} {$c['version']}"
                . ($v->cvss_score !== null ? ' (CVSS ' . number_format($v->cvss_score, 1) . ')' : '')
                . ($fixed ? ": bijwerken naar {$fixed}" : ': nog geen oplossing');
            $add($v->level(), 'beveiliging', $text, ($fixed ? ['component' => ['type' => $c['type'], 'slug' => $c['slug']]] : []) + ['key' => 'vuln:' . $c['type'] . ':' . $c['slug']]);
        }
        foreach ((array) (($site->inventory ?? [])['licenses'] ?? []) as $key => $state) {
            if (is_array($state) && ($state['active'] ?? null) === false) {
                $name = ['elementor_pro' => 'Elementor Pro', 'acf_pro' => 'ACF Pro', 'rank_math_pro' => 'Rank Math Pro'][$key] ?? $key;
                $add('warn', 'updates', "{$name}: licentie niet actief, dus geen updates", ['license' => $key]);
            }
        }
        if (\App\Ocm\Menu::enabled('links')) {
            $broken = \App\Models\BrokenLink::query()->where('site_id', $site->id)->whereNull('ignored_at')->get(['internal']);
            if ($broken->isNotEmpty()) {
                $internal = $broken->where('internal', true)->count();
                $add($internal > 0 ? 'warn' : 'info', 'prestaties', $broken->count() . ' kapotte link' . ($broken->count() === 1 ? '' : 's') . ($internal > 0 ? " ({$internal} intern)" : ''));
            }
        }
        $errors = \App\Models\PhpError::query()->where('site_id', $site->id)->where('last_seen_at', '>=', now()->subDays(7))->get()->filter->isOpen();
        if ($errors->isNotEmpty()) {
            $recent = $errors->filter(fn ($e) => $e->last_seen_at->gt(now()->subDay()));
            $add($recent->isNotEmpty() ? 'warn' : 'info', 'beschikbaarheid', $errors->count() . ' PHP-fout' . ($errors->count() === 1 ? '' : 'en')
                . ($recent->isNotEmpty() ? ' (' . $recent->sum('count') . 'x in 24 uur)' : ' deze week'));
        }
        if ($visual !== null && $visual->changed()) {
            $add('warn', 'updates', 'Homepage zichtbaar veranderd na updates (' . number_format($visual->diff_percent, 0) . '%): controleer de site', ['visual' => $visual->id]);
        }
        $wpUsers = $site->wp_users ?? null;
        if (is_array($wpUsers) && is_array($wpUsers['users'] ?? null)) {
            $admins = collect($wpUsers['users'])->filter(fn ($u) => in_array('administrator', (array) ($u['roles'] ?? []), true) && empty($u['locked']));
            $without = $admins->filter(fn ($u) => empty($u['two_factor']))->count();
            if ($without > 0) {
                $add('warn', 'beveiliging', "{$without} beheerder" . ($without === 1 ? '' : 's') . ' zonder tweestapsverificatie', ['two_factor' => true, 'key' => 'admins-2fa']);
            }
            if ($admins->count() > 5) {
                $add('info', 'beveiliging', $admins->count() . ' beheerders: is dat nodig?', ['key' => 'admins-many']);
            }
        }
        if ($site->event_gap_at !== null) {
            $add('warn', 'beveiliging', 'Gat in wijzigingslog: mogelijk zijn events op de site verwijderd');
        }

        self::environment($site, $add);
        self::health($site, $add, self::$directory ?? []);
        self::events($events, $add);

        // Updates
        $inventory = $site->inventory ?? [];
        if (!empty($inventory['core']['update']['version'])) {
            $add('warn', 'updates', 'WordPress ' . $inventory['core']['update']['version'] . ' beschikbaar (nu ' . ($inventory['core']['version'] ?? '?') . ')', ['updates' => ['core'], 'key' => 'core-update']);
        }
        $components = collect($site->availableUpdates())->whereIn('type', ['plugin', 'theme']);
        if ($client = collect($site->availableUpdates())->firstWhere('type', 'client')) {
            $add('info', 'updates', 'Client-plugin ' . $client['to'] . ' beschikbaar (nu ' . $client['from'] . ')', ['updates' => ['client'], 'key' => 'client-update']);
        }
        if ($components->isNotEmpty()) {
            $plugins = $components->where('type', 'plugin')->count();
            $themes = $components->where('type', 'theme')->count();
            $parts = array_filter([$plugins ? $plugins . ' plugin' . ($plugins === 1 ? '' : 's') : null, $themes ? $themes . ' thema' . ($themes === 1 ? '' : '\'s') : null]);
            $add('warn', 'updates', 'Updates beschikbaar: ' . implode(' en ', $parts), ['updates' => ['plugin', 'theme'], 'key' => 'updates']);
        }
        if ($reason = $site->updatesBlockedReason()) {
            $add('info', 'koppeling', $reason);
        }

        // Prestaties
        $latest = $pagespeed->first();
        if ($latest !== null) {
            $previous = $pagespeed->skip(1)->first();
            $drop = (int) config('ocm.pagespeed.drop_alert_points', 10);
            if ($previous !== null && $previous->performance - $latest->performance >= $drop) {
                $add('warn', 'prestaties', "PageSpeed mobiel gedaald van {$previous->performance} naar {$latest->performance}", ['pagespeed' => true, 'key' => 'pagespeed']);
            } elseif ($latest->performance < 50) {
                $add('warn', 'prestaties', "PageSpeed mobiel {$latest->performance}", ['pagespeed' => true, 'key' => 'pagespeed']);
            }
        }

        usort($issues, fn ($a, $b) => self::LEVEL_RANK[$a['level']] <=> self::LEVEL_RANK[$b['level']]
            ?: array_search($a['category'], array_keys(self::CATEGORIES), true) <=> array_search($b['category'], array_keys(self::CATEGORIES), true));

        return $issues;
    }

    /**
     * Stabiele sleutel van een issue voor meldingen: cijfers en tekst tussen haakjes tellen niet mee,
     * zodat "verloopt over 13 dagen" en "over 12 dagen" hetzelfde incident blijven.
     */
    public static function key(string $category, string $text): string
    {
        $stem = preg_replace(['/\([^)]*\)/u', '/\d[\d.,:]*/u', '/\s+/u'], ['', '#', ' '], mb_strtolower($text));

        return $category . ':' . substr(sha1(trim((string) $stem)), 0, 16);
    }

    /**
     * @param list<array{level: string}> $issues
     */
    public static function levelOf(Site $site, array $issues): string
    {
        if (!$site->isPaired()) {
            return 'pending';
        }
        $levels = array_column($issues, 'level');

        return in_array('crit', $levels, true) ? 'crit' : (in_array('warn', $levels, true) ? 'warn' : 'ok');
    }

    /**
     * @param callable(string, string, string): void $add
     */
    private static function environment(Site $site, callable $add): void
    {
        $env = ($site->inventory ?? [])['environment'] ?? null;
        if (is_array($env)) {
            if (($env['https'] ?? true) === false) {
                $add('crit', 'beveiliging', 'Site draait niet op HTTPS');
            }
            $role = (string) ($env['default_role'] ?? '');
            if (!empty($env['users_can_register']) && in_array($role, self::PRIVILEGED_ROLES, true)) {
                $add('crit', 'beveiliging', "Iedereen kan zich registreren als {$role}");
            } elseif (!empty($env['users_can_register'])) {
                $add('info', 'beveiliging', 'Registratie van nieuwe gebruikers staat open');
            }
            if (!empty($env['wp_debug'])) {
                $add('warn', 'beveiliging', 'WP_DEBUG staat aan');
            }
            if (($env['disallow_file_edit'] ?? true) === false) {
                $add('info', 'beveiliging', 'Bestandseditor in WP-admin staat aan (DISALLOW_FILE_EDIT)');
            }
            if (($env['file_mods_allowed'] ?? true) === false) {
                $add('info', 'updates', 'Updates zijn op de site uitgeschakeld (DISALLOW_FILE_MODS)');
            }
            if (($env['blog_public'] ?? true) === false) {
                $add('info', 'prestaties', 'Verborgen voor zoekmachines');
            }
            $login = $env['login_security'] ?? null;
            if (is_array($login)) {
                if (empty($login['lockout'])) {
                    $add('info', 'beveiliging', 'Blokkade na mislukte inlogpogingen staat uit');
                }
                if (($login['xmlrpc'] ?? 'on') === 'on') {
                    $add('info', 'beveiliging', 'Inloggen via XML-RPC staat aan' . (($login['xmlrpc_mode'] ?? '') === 'auto' ? ' (Jetpack is actief)' : ''));
                }
                if (empty($login['hide_users'])) {
                    $add('info', 'beveiliging', 'Gebruikersnamen zijn openbaar op te vragen (REST API, ?author=)');
                }
            } elseif ($site->plugin_version !== null && version_compare($site->plugin_version, '0.3.0', '<')) {
                $add('info', 'koppeling', 'Client-plugin ' . $site->plugin_version . ': werk bij naar 0.3.0 voor inlogbeveiliging');
            }
            if ($site->plugin_version !== null && version_compare($site->plugin_version, '0.4.0', '<')) {
                $add('info', 'koppeling', 'Client-plugin ' . $site->plugin_version . ': installeer eenmalig 0.4.0 met de hand; daarna werkt hij zichzelf bij');
            }
        }

        $inactive = collect(($site->inventory ?? [])['plugins'] ?? [])->filter(fn ($p) => is_array($p) && empty($p['active']))->count();
        if ($inactive > 0) {
            $add('info', 'beveiliging', $inactive . ' inactieve plugin' . ($inactive === 1 ? '' : 's') . ' (verwijderen verkleint het aanvalsoppervlak)', ['key' => 'inactive-plugins']);
        }

        if ($site->php_version && preg_match('/^(\d+\.\d+)/', $site->php_version, $m)) {
            $eol = self::PHP_EOL[$m[1]] ?? (version_compare($m[1], '7.4', '<') ? '2000-01-01' : null);
            if ($eol !== null && now()->gt($eol)) {
                $add('warn', 'beveiliging', "PHP {$m[1]} krijgt geen beveiligingsupdates meer", ['key' => 'php-eol']);
            } elseif ($eol !== null && now()->addDays(90)->gt($eol)) {
                $add('info', 'beveiliging', "PHP {$m[1]}: beveiligingsupdates stoppen op " . \Illuminate\Support\Carbon::parse($eol)->format('d-m-Y'), ['key' => 'php-eol']);
            }
        }
    }

    /**
     * Integriteit (plugin 0.9.0+), WP-Cron, onderhoudsmodus, inactieve thema's, gesloten en
     * verlaten plugins en een grote autoload.
     *
     * @param callable(string, string, string, array<string, mixed>=): void $add
     * @param array<string, \App\Models\PluginDirectoryEntry> $directory
     */
    private static function health(Site $site, callable $add, array $directory): void
    {
        $inv = $site->inventory ?? [];
        $integrity = $site->integrity;
        if (is_array($integrity)) {
            $core = $integrity['core'] ?? null;
            if (is_array($core)) {
                $n = (int) (($core['counts']['modified'] ?? 0) + ($core['counts']['unknown'] ?? 0));
                if ($n > 0) {
                    $add('crit', 'beveiliging', "WordPress-bestanden gewijzigd of toegevoegd ({$n}): vergelijk met wordpress.org", ['key' => 'integrity-core']);
                }
            }
            $root = (array) ($integrity['root_unknown'] ?? []);
            if ($root !== []) {
                $add('crit', 'beveiliging', 'Onbekende PHP-bestanden in de hoofdmap: ' . implode(', ', array_slice($root, 0, 5)) . (count($root) > 5 ? ' …' : ''), ['key' => 'integrity-root']);
            }
            $uploads = (int) ($integrity['uploads_php_count'] ?? 0);
            if ($uploads > 0) {
                $add('crit', 'beveiliging', "PHP-bestanden in uploads ({$uploads}): daar hoort geen code, mogelijk een webshell", ['key' => 'integrity-uploads']);
            }
            foreach ((array) ($integrity['plugins'] ?? []) as $slug => $r) {
                if (is_array($r) && ($r['status'] ?? '') === 'modified') {
                    $n = (int) (($r['counts']['modified'] ?? 0) + ($r['counts']['unknown'] ?? 0));
                    $add('warn', 'beveiliging', "Plugin {$slug} wijkt af van wordpress.org ({$n} bestand" . ($n === 1 ? '' : 'en') . ')', ['key' => 'integrity-plugin:' . $slug]);
                }
            }
        }

        $cron = $inv['cron'] ?? null;
        if (is_array($cron) && (int) ($cron['overdue'] ?? 0) > 0 && (int) ($cron['oldest_overdue_seconds'] ?? 0) >= 6 * 3600) {
            $hours = intdiv((int) $cron['oldest_overdue_seconds'], 3600);
            $add('warn', 'beschikbaarheid', 'WP-Cron loopt achter: ' . (int) $cron['overdue'] . ' taak/taken te laat, de oudste ' . ($hours >= 48 ? intdiv($hours, 24) . ' dagen' : $hours . ' uur')
                . (!empty($cron['disabled']) ? ' (DISABLE_WP_CRON staat aan: controleer de servercron)' : ''), ['key' => 'cron']);
        }

        $maintenance = $inv['maintenance_mode'] ?? null;
        if (is_array($maintenance) && !empty($maintenance['enabled'])) {
            $until = !empty($maintenance['until']) ? ' tot ' . \Illuminate\Support\Carbon::createFromTimestamp((int) $maintenance['until'])->timezone('Europe/Amsterdam')->format('d-m H:i') : '';
            $add('warn', 'beschikbaarheid', 'Onderhoudsmodus staat aan' . $until . ': bezoekers zien de site niet', ['key' => 'maintenance']);
        }

        // Inactieve thema's, behalve het hoofdthema van een child-thema en één standaardthema als reserve.
        $themes = collect((array) ($inv['themes'] ?? []))->filter(fn ($t) => is_array($t));
        $parent = $themes->firstWhere('active', true)['parent'] ?? null;
        $inactive = $themes->filter(fn ($t) => empty($t['active']) && ($t['stylesheet'] ?? null) !== $parent);
        $spare = $inactive->contains(fn ($t) => str_starts_with((string) ($t['stylesheet'] ?? ''), 'twenty')) ? 1 : 0;
        $extra = $inactive->count() - $spare;
        if ($extra > 0) {
            $add('info', 'beveiliging', $extra . ' inactie' . ($extra === 1 ? 'f thema' : 've thema\'s') . ' (verwijderen verkleint het aanvalsoppervlak; één standaardthema als reserve is genoeg)', ['key' => 'inactive-themes']);
        }

        foreach ((array) ($inv['plugins'] ?? []) as $p) {
            $entry = is_array($p) ? ($directory[(string) ($p['slug'] ?? '')] ?? null) : null;
            if ($entry === null) {
                continue;
            }
            if ($entry->closed) {
                $add('warn', 'beveiliging', "Plugin {$p['name']} is gesloten op wordpress.org" . ($entry->closed_reason ? " ({$entry->closed_reason})" : '') . ': zoek een vervanger', ['key' => 'abandoned:' . $p['slug']]);
            } elseif ($entry->last_updated !== null && $entry->last_updated->lt(now()->subYears(PluginDirectory::ABANDONED_YEARS))) {
                $add('info', 'beveiliging', "Plugin {$p['name']} is sinds " . $entry->last_updated->format('m-Y') . ' niet bijgewerkt (mogelijk verlaten)', ['key' => 'abandoned:' . $p['slug']]);
            }
        }

        $autoload = (int) (($inv['cleanup'] ?? [])['autoload_bytes'] ?? 0);
        if ($autoload > 2 * 1024 * 1024) {
            $add('warn', 'prestaties', 'Autoload-opties zijn ' . number_format($autoload / 1048576, 1, ',', '') . ' MB: dat wordt bij elke pagina geladen', ['key' => 'autoload']);
        } elseif ($autoload > 1024 * 1024) {
            $add('info', 'prestaties', 'Autoload-opties zijn ' . number_format($autoload / 1048576, 1, ',', '') . ' MB', ['key' => 'autoload']);
        }
    }

    /**
     * @param Collection<int, SiteEvent> $events
     * @param callable(string, string, string): void $add
     */
    private static function events(Collection $events, callable $add): void
    {
        $external = 0;
        $failedLogins = 0;
        $lockedIps = 0;
        $updates = []; // object => laatste update-event

        foreach ($events as $event) {
            $date = $event->occurred_at->timezone('Europe/Amsterdam')->format('d-m');
            switch ($event->type) {
                case 'user.created':
                case 'user.role_changed':
                    $roles = explode(',', (string) $event->to_version);
                    if (in_array('administrator', $roles, true)) {
                        $trusted = in_array($event->source, self::TRUSTED_SOURCES, true);
                        $verb = $event->type === 'user.created' ? 'Nieuwe beheerder' : 'Beheerdersrol gegeven aan';
                        $add($trusted ? 'warn' : 'crit', 'beveiliging', "{$verb} {$event->object} ({$event->sourceLabel()}, {$date})");
                    }
                    break;
                case 'option.changed':
                    if (in_array($event->object, ['siteurl', 'home'], true)) {
                        $add('crit', 'beveiliging', "Site-adres gewijzigd ({$event->object}: {$event->from_version} → {$event->to_version}, {$date})");
                    } elseif ($event->object === 'default_role' && $event->to_version === 'administrator') {
                        $add('crit', 'beveiliging', "Standaardrol voor nieuwe gebruikers is administrator geworden ({$date})");
                    } elseif ($event->object === 'admin_email') {
                        $add('warn', 'beveiliging', "Beheerders-e-mailadres gewijzigd ({$date})");
                    } elseif ($event->object === 'users_can_register' && $event->to_version === '1') {
                        $add('warn', 'beveiliging', "Registratie van gebruikers opengezet ({$date})");
                    }
                    break;
                case 'component.changed_externally':
                    $external++;
                    break;
                case 'login.failed_summary':
                    if ($event->occurred_at->gte(now()->subDay())) {
                        $failedLogins += (int) (($event->data ?? [])['count'] ?? 0);
                        $lockedIps += (int) (($event->data ?? [])['locked'] ?? 0);
                    }
                    break;
                case 'plugin.rolled_back':
                case 'theme.rolled_back':
                    $add('warn', 'updates', "Update van {$event->object} teruggedraaid na een fout ({$date})");
                    break;
                default: // plugin.update / theme.update
                    $updates[$event->object] = $event;
            }
        }

        foreach ($updates as $object => $event) {
            if ($event->result === 'failed') {
                $add('warn', 'updates', "Update van {$object} mislukt ({$event->occurred_at->timezone('Europe/Amsterdam')->format('d-m')})");
            }
        }
        if ($external > 0) {
            $add('warn', 'beveiliging', "{$external} wijziging" . ($external === 1 ? '' : 'en') . ' aan plugins/thema\'s buiten WordPress om (FTP of deploy?)', ['key' => 'external-changes']);
        }
        if ($failedLogins >= 50) {
            // Met actieve blokkade is het een aanval die al wordt tegengehouden: alleen ter info.
            $protected = !empty(($site->inventory['environment']['login_security'] ?? [])['lockout']);
            $add($protected ? 'info' : 'warn', 'beveiliging', "{$failedLogins} mislukte inlogpogingen in 24 uur" . ($lockedIps > 0 ? " ({$lockedIps}x een IP geblokkeerd)" : ''), ['key' => 'failed-logins']);
        }
    }
}
