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

    /** Hoe ver terug events meetellen (nieuwe beheerders, gewijzigde site-URL e.d.). */
    public const EVENT_WINDOW_DAYS = 14;

    /** Einde van de beveiligingsupdates per PHP-versie (php.net/supported-versions). */
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
        $paired = $sites->filter(fn (Site $s) => $s->isPaired());
        $ids = $paired->pluck('id')->all();
        $events = collect();
        $pagespeed = collect();
        if ($ids !== []) {
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
            $site->setIssues(self::forSite($site, $events->get($site->id, collect()), $pagespeed->get($site->id, collect())));
        }
    }

    /**
     * @param Collection<int, SiteEvent> $events oplopend in tijd
     * @param Collection<int, PagespeedResult> $pagespeed mobiel, nieuwste eerst
     * @return list<array{level: string, category: string, text: string}>
     */
    public static function forSite(Site $site, Collection $events, Collection $pagespeed): array
    {
        if (!$site->isPaired()) {
            return [['level' => 'info', 'category' => 'koppeling', 'text' => $site->pairingIsOpen() ? 'Wacht op koppeling' : 'Koppelcode verlopen']];
        }

        $issues = [];
        $add = function (string $level, string $category, string $text) use (&$issues): void {
            $issues[] = ['level' => $level, 'category' => $category, 'text' => $text];
        };

        // Beschikbaarheid en koppeling
        if ($site->status === 'unreachable') {
            $add('crit', 'beschikbaarheid', 'Niet bereikbaar');
        } elseif ($site->status === 'error') {
            $add('crit', 'beschikbaarheid', 'Fout bij laatste contact');
        }
        $heartbeat = (int) config('ocm.heartbeat_minutes') * 3;
        if ($site->last_seen_at === null || $site->last_seen_at->lt(now()->subMinutes($heartbeat))) {
            $add('warn', 'beschikbaarheid', 'Geen heartbeat sinds ' . ($site->last_seen_at?->diffForHumans() ?? 'koppeling'));
        }
        if ($site->event_gap_at !== null) {
            $add('warn', 'beveiliging', 'Gat in wijzigingslog: mogelijk zijn events op de site verwijderd');
        }

        self::environment($site, $add);
        self::events($events, $add);

        // Updates
        $inventory = $site->inventory ?? [];
        if (!empty($inventory['core']['update']['version'])) {
            $add('warn', 'updates', 'WordPress ' . $inventory['core']['update']['version'] . ' beschikbaar (nu ' . ($inventory['core']['version'] ?? '?') . ')');
        }
        $components = collect($site->availableUpdates())->where('type', '!=', 'core');
        if ($components->isNotEmpty()) {
            $plugins = $components->where('type', 'plugin')->count();
            $themes = $components->where('type', 'theme')->count();
            $parts = array_filter([$plugins ? $plugins . ' plugin' . ($plugins === 1 ? '' : 's') : null, $themes ? $themes . ' thema' . ($themes === 1 ? '' : '\'s') : null]);
            $add('warn', 'updates', 'Updates beschikbaar: ' . implode(' en ', $parts));
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
                $add('warn', 'prestaties', "PageSpeed mobiel gedaald van {$previous->performance} naar {$latest->performance}");
            } elseif ($latest->performance < 50) {
                $add('warn', 'prestaties', "PageSpeed mobiel {$latest->performance}");
            }
        }

        usort($issues, fn ($a, $b) => self::LEVEL_RANK[$a['level']] <=> self::LEVEL_RANK[$b['level']]
            ?: array_search($a['category'], array_keys(self::CATEGORIES), true) <=> array_search($b['category'], array_keys(self::CATEGORIES), true));

        return $issues;
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
        }

        $inactive = collect(($site->inventory ?? [])['plugins'] ?? [])->filter(fn ($p) => is_array($p) && empty($p['active']))->count();
        if ($inactive > 0) {
            $add('info', 'beveiliging', $inactive . ' inactieve plugin' . ($inactive === 1 ? '' : 's') . ' (verwijderen verkleint het aanvalsoppervlak)');
        }

        if ($site->php_version && preg_match('/^(\d+\.\d+)/', $site->php_version, $m)) {
            $eol = self::PHP_EOL[$m[1]] ?? (version_compare($m[1], '7.4', '<') ? '2000-01-01' : null);
            if ($eol !== null && now()->gt($eol)) {
                $add('warn', 'beveiliging', "PHP {$m[1]} krijgt geen beveiligingsupdates meer");
            } elseif ($eol !== null && now()->addDays(90)->gt($eol)) {
                $add('info', 'beveiliging', "PHP {$m[1]}: beveiligingsupdates stoppen op " . \Illuminate\Support\Carbon::parse($eol)->format('d-m-Y'));
            }
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
            $add('warn', 'beveiliging', "{$external} wijziging" . ($external === 1 ? '' : 'en') . ' aan plugins/thema\'s buiten WordPress om (FTP of deploy?)');
        }
        if ($failedLogins >= 50) {
            $add('warn', 'beveiliging', "{$failedLogins} mislukte inlogpogingen in 24 uur");
        }
    }
}
