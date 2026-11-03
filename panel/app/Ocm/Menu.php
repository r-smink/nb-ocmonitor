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
        'users' => ['Gebruikers', 'users.index', ['users.*'], true],
    ];

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
