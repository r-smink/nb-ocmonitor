<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\Tag;
use App\Ocm\SiteIssues;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** PHP-, WordPress- en pluginversies van alle sites op één pagina. */
class VersionController extends Controller
{
    public function index(Request $request): View
    {
        $sites = Tag::filter(Site::query()->with(['tags', 'customer.tags'])->whereNotNull('public_key')->orderBy('name')->get(), $request->integer('tag') ?: null);

        // Nieuwste WordPress: wat sites als update aangeboden krijgen, of de hoogste geïnstalleerde versie.
        $latestWp = $sites->map(fn (Site $s) => ($s->inventory['core']['update']['version'] ?? null) ?: $s->wp_version)->filter()
            ->reduce(fn ($max, $v) => $max === null || version_compare($v, $max, '>') ? $v : $max);

        $rows = $sites->map(function (Site $site) use ($latestWp) {
            $inv = $site->inventory ?? [];
            $php = preg_match('/^(\d+\.\d+)/', (string) $site->php_version, $m) ? $m[1] : null;
            $eol = $php ? SiteIssues::phpEol($php) : null;
            $plugins = collect((array) ($inv['plugins'] ?? []));
            $active = collect((array) ($inv['themes'] ?? []))->firstWhere('active', true);

            return [
                'site' => $site,
                'wp' => $site->wp_version,
                'wp_outdated' => $site->wp_version && $latestWp && version_compare($site->wp_version, $latestWp, '<'),
                'php' => $site->php_version,
                'php_minor' => $php,
                'php_eol' => $eol,
                'php_state' => $eol === null ? null : (now()->gt($eol) ? 'crit' : (now()->addMonths(6)->gt($eol) ? 'warn' : 'ok')),
                'db' => $inv['db_server'] ?? null,
                'plugin' => $site->plugin_version,
                'plugins' => $plugins->count(),
                'inactive' => $plugins->where('active', false)->count(),
                'updates' => count($site->availableUpdates()),
                'theme' => $active ? ($active['name'] ?? $active['stylesheet']) . ' ' . ($active['version'] ?? '') : null,
                'db_mb' => isset($inv['cleanup']['db_bytes']) ? round($inv['cleanup']['db_bytes'] / 1048576, 1) : null,
            ];
        });

        return view('versions.index', [
            'rows' => $rows,
            'latestWp' => $latestWp,
            'phpCounts' => $rows->groupBy(fn ($r) => $r['php_minor'] ?? 'onbekend')->map->count()->sortKeysDesc(),
            'wpCounts' => $rows->groupBy(fn ($r) => $r['wp'] ? implode('.', array_slice(explode('.', $r['wp']), 0, 2)) : 'onbekend')->map->count()->sortKeysDesc(),
        ]);
    }
}
