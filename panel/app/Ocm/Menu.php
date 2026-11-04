<?php

namespace App\Ocm;

/**
 * Het hoofdmenu. Per onderdeel instelbaar (Instellingen): zichtbaar, verborgen (niet in het menu,
 * wel bereikbaar) of uit (pagina's en acties geven 404 en de blokken elders verdwijnen).
 */
final class Menu
{
    public const STATES = ['visible' => 'Zichtbaar', 'hidden' => 'Verborgen in het menu', 'off' => 'Uit'];

    /**
     * key => [label, route, route-patronen die bij dit onderdeel horen, vast (niet uit te zetten)]
     */
    public const ITEMS = [
        'dashboard' => ['Overzicht', 'dashboard', ['dashboard'], true],
        'sites' => ['Sites', 'sites.index', ['sites.*'], true],
        'customers' => ['Klanten', 'customers.index', ['customers.*', 'tags.*'], false],
        'updates' => ['Updates', 'updates.index', ['updates.*', 'sites.updates'], false],
        'pagespeed' => ['PageSpeed', 'pagespeed.index', ['pagespeed.*', 'sites.pagespeed'], false],
        'vulnerabilities' => ['Kwetsbaarheden', 'vulnerabilities.index', ['vulnerabilities.*'], false],
        'versions' => ['Versies', 'versions.index', ['versions.*'], false],
        'licenses' => ['Licenties', 'licenses.*', ['licenses.*'], false],
        'wp-users' => ['WP-gebruikers', 'wp-users.index', ['wp-users.*', 'sites.two-factor'], false],
        'reports' => ['Rapporten', 'reports.index', ['reports.*', 'customers.report', 'customers.report.send'], false],
        'activity' => ['Activiteit', 'activity.index', ['activity.*'], false],
        'bulk' => ['Bulk-acties', 'bulk.index', ['bulk.*'], false],
    ];

    /** Indeling van de zijbalk: groep => onderdelen, in deze volgorde. */
    public const GROUPS = [
        'Monitoring' => ['dashboard', 'sites', 'pagespeed', 'vulnerabilities'],
        'Onderhoud' => ['updates', 'customers', 'reports', 'versions'],
        'Beheer' => ['licenses', 'wp-users', 'bulk', 'activity'],
    ];

    /** Icoon per onderdeel (namen uit partials/icon). */
    public const ICONS = [
        'dashboard' => 'grid', 'sites' => 'server', 'pagespeed' => 'gauge', 'vulnerabilities' => 'bug',
        'updates' => 'refresh', 'customers' => 'calendar', 'reports' => 'filetext', 'versions' => 'layers',
        'licenses' => 'key', 'wp-users' => 'users', 'bulk' => 'check', 'activity' => 'activity',
    ];

    /**
     * De zichtbare menu-items per groep; lege groepen vallen weg.
     *
     * @return array<string, list<array{key: string, label: string, route: string, patterns: string[], icon: string}>>
     */
    public static function grouped(): array
    {
        $visible = collect(self::visible())->keyBy('key');
        $out = [];
        foreach (self::GROUPS as $group => $keys) {
            foreach ($keys as $key) {
                if ($item = $visible->get($key)) {
                    $out[$group][] = $item + ['icon' => self::ICONS[$key] ?? 'grid'];
                }
            }
        }

        return $out;
    }

    /**
     * Aantallen voor de badges in de zijbalk (alleen > 0 tonen) en de verbindingsstatus.
     * Kwetsbaarheden zijn duur om uit te rekenen en worden een paar minuten gecachet.
     *
     * @return array{updates: int, vulnerabilities: int, paired: int, connected: int}
     */
    public static function counts(): array
    {
        $paired = \App\Models\Site::query()->whereNotNull('public_key');
        $vulns = self::enabled('vulnerabilities') ? (int) \Illuminate\Support\Facades\Cache::remember('ocm.nav.vulnerabilities', 300, function () {
            $sites = \App\Models\Site::query()->whereNotNull('public_key')->get();
            $index = Vulnerabilities::index(array_keys(Vulnerabilities::componentKeys($sites)));
            $count = 0;
            foreach ($sites as $site) {
                foreach (Vulnerabilities::forSite($site, $index) as $match) {
                    $count += $match['vulnerability']->level() !== 'info' ? 1 : 0;
                }
            }

            return $count;
        }) : 0;

        return [
            'updates' => self::enabled('updates') ? (int) (clone $paired)->sum('updates_count') : 0,
            'vulnerabilities' => $vulns,
            'paired' => (clone $paired)->count(),
            'connected' => (clone $paired)->whereNotIn('status', ['unreachable', 'error'])->count(),
        ];
    }

    public static function state(string $key): string
    {
        if (!isset(self::ITEMS[$key]) || self::ITEMS[$key][3]) {
            return 'visible';
        }
        $state = AppSettings::get('menu')[$key] ?? 'visible';

        return isset(self::STATES[$state]) ? $state : 'visible';
    }

    public static function enabled(string $key): bool
    {
        return self::state($key) !== 'off';
    }

    /** @return list<array{key: string, label: string, route: string, patterns: string[]}> */
    public static function visible(): array
    {
        $out = [];
        foreach (self::ITEMS as $key => [$label, $route, $patterns]) {
            if (self::state($key) === 'visible') {
                $out[] = ['key' => $key, 'label' => $label, 'route' => str_ends_with($route, '.*') ? substr($route, 0, -2) . '.index' : $route, 'patterns' => $patterns];
            }
        }

        return $out;
    }

    /** Bij welk onderdeel hoort deze route (voor de 404 als het onderdeel uit staat)? */
    public static function keyForRoute(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }
        // Specifieke patronen (zonder *) eerst, zodat sites.updates bij Updates hoort en niet bij Sites.
        foreach ([false, true] as $wildcard) {
            foreach (self::ITEMS as $key => [, , $patterns]) {
                foreach ($patterns as $pattern) {
                    if (str_contains($pattern, '*') === $wildcard && \Illuminate\Support\Str::is($pattern, $routeName)) {
                        return $key;
                    }
                }
            }
        }

        return null;
    }
}
