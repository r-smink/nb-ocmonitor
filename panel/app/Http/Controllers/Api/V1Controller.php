<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Ocm\SiteIssues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alleen-lezen API voor eigen koppelingen (dashboards, facturatie, ...). Geeft geen geheimen
 * terug: geen sleutels, koppelcodes of WP-gebruikers.
 */
class V1Controller extends Controller
{
    public function sites(Request $request): JsonResponse
    {
        $sites = Site::query()->with(['tags', 'customer.tags'])->orderBy('name')->get();
        if ($request->filled('tag')) {
            $sites = $sites->filter(fn (Site $s) => $s->allTags()->contains('name', (string) $request->query('tag')))->values();
        }
        SiteIssues::load($sites);

        return response()->json(['data' => $sites->map(fn (Site $s) => self::summary($s))->values()]);
    }

    public function site(string $siteId): JsonResponse
    {
        $site = Site::query()->with(['tags', 'customer.tags'])->where('site_id', $siteId)->firstOrFail();
        SiteIssues::load(collect([$site]));

        return response()->json(['data' => self::summary($site) + [
            'issues' => array_map(fn ($i) => ['level' => $i['level'], 'category' => $i['category'], 'text' => $i['text']], $site->issues()),
            'updates' => array_map(fn ($u) => ['type' => $u['type'], 'slug' => $u['slug'], 'name' => $u['name'], 'from' => $u['from'], 'to' => $u['to']], $site->availableUpdates()),
            'ssl_expires_at' => $site->ssl_expires_at?->toIso8601String(),
            'domain_expires_at' => $site->domain_expires_at?->toIso8601String(),
            'uptime_30d' => \App\Ocm\Monitoring\Monitor::stats($site, \Carbon\CarbonImmutable::now()->subDays(30), \Carbon\CarbonImmutable::now())['percent'],
        ]]);
    }

    public function updates(): JsonResponse
    {
        $rows = [];
        foreach (Site::query()->whereNotNull('public_key')->orderBy('name')->get() as $site) {
            foreach ($site->availableUpdates() as $u) {
                $rows[] = ['site_id' => $site->site_id, 'site' => $site->name, 'type' => $u['type'], 'slug' => $u['slug'], 'name' => $u['name'], 'from' => $u['from'], 'to' => $u['to']];
            }
        }

        return response()->json(['data' => $rows]);
    }

    /** @return array<string, mixed> */
    private static function summary(Site $s): array
    {
        $issues = collect($s->issues());

        return [
            'site_id' => $s->site_id,
            'name' => $s->name,
            'url' => $s->url,
            'customer' => $s->customer?->name ?? $s->client_name,
            'tags' => $s->allTags()->pluck('name')->values(),
            'paired' => $s->isPaired(),
            'level' => $s->level(),
            'status' => $s->status,
            'uptime_state' => $s->uptime_state,
            'wordpress' => $s->wp_version,
            'php' => $s->php_version,
            'plugin' => $s->plugin_version,
            'updates_count' => (int) $s->updates_count,
            'issues_count' => ['crit' => $issues->where('level', 'crit')->count(), 'warn' => $issues->where('level', 'warn')->count(), 'info' => $issues->where('level', 'info')->count()],
            'last_seen_at' => $s->last_seen_at?->toIso8601String(),
        ];
    }
}
