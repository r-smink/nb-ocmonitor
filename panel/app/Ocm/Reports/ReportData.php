<?php

namespace App\Ocm\Reports;

use App\Models\Customer;
use App\Models\MaintenanceRun;
use App\Models\PagespeedResult;
use App\Models\Site;
use App\Models\SiteEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Verzamelt per klant en periode wat er in het rapport komt. Alleen gegevens die het panel zelf
 * heeft: het wijzigingslog van de sites, de inventaris, PageSpeed en de onderhoudsmomenten.
 */
final class ReportData
{
    private const UPDATE_TYPES = ['plugin.update', 'theme.update', 'core.updated', 'translation.updated'];

    private const CHANGE_LABELS = [
        'plugin.install' => 'Plugin geïnstalleerd',
        'plugin.activated' => 'Plugin geactiveerd',
        'plugin.deactivated' => 'Plugin gedeactiveerd',
        'plugin.deleted' => 'Plugin verwijderd',
        'theme.install' => 'Thema geïnstalleerd',
        'theme.switched' => 'Ander thema actief',
        'theme.deleted' => 'Thema verwijderd',
        'user.created' => 'Gebruiker aangemaakt',
        'user.deleted' => 'Gebruiker verwijderd',
        'user.role_changed' => 'Rol van gebruiker gewijzigd',
        'option.changed' => 'Instelling gewijzigd',
        'component.changed_externally' => 'Gewijzigd buiten WordPress om',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function build(Customer $customer, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sites = $customer->sites()->get();
        $start = $from->startOfDay();
        $end = $to->endOfDay();
        $events = SiteEvent::query()->whereIn('site_id', $sites->pluck('id'))
            ->whereBetween('occurred_at', [$start, $end])->orderBy('occurred_at')->get()->groupBy('site_id');
        $pagespeed = PagespeedResult::latestPerSite();

        $siteData = $sites->map(fn (Site $site) => self::site($site, $events->get($site->id, collect()), $pagespeed->get($site->id, collect()), $start, $end))->all();

        $runs = $customer->runs()->with('doneBy')->whereBetween('due_on', [$start->toDateString(), $end->toDateString()])->orderBy('due_on')->get()
            ->map(fn (MaintenanceRun $r) => ['date' => $r->due_on, 'done_at' => $r->done_at, 'by' => $r->doneBy?->name, 'notes' => $r->notes])->all();

        return [
            'customer' => ['name' => $customer->reportName(), 'company_name' => $customer->name, 'domains' => $customer->domainLabel(), 'contact' => $customer->contact_name, 'contract' => $customer->contractLabel()],
            'from' => $from,
            'to' => $to,
            'sections' => $customer->sections(),
            'sites' => $siteData,
            'runs' => $runs,
            'cover_shot' => self::coverShot($sites),
            'totals' => [
                'sites' => count($siteData),
                'updates' => array_sum(array_map(fn ($s) => count(array_filter($s['updates'], fn ($u) => $u['ok'])), $siteData)),
                'pending' => array_sum(array_map(fn ($s) => count($s['pending']), $siteData)),
                'failed_logins' => array_sum(array_column(array_column($siteData, 'security'), 'failed_logins')),
                'blocked_ips' => array_sum(array_column(array_column($siteData, 'security'), 'locked')),
            ],
        ];
    }

    /**
     * Screenshot van de homepage na de laatste updates (visuele controle) van de eerste site die er
     * een heeft, voor de voorpagina. null = geen opname, dan laat de voorpagina het kader weg.
     *
     * @param Collection<int, Site> $sites
     * @return array{url: string, image: string, at: ?CarbonImmutable}|null image = base64 JPEG
     */
    private static function coverShot(Collection $sites): ?array
    {
        foreach ($sites->filter(fn (Site $s) => $s->isPaired()) as $site) {
            $check = \App\Models\VisualCheck::query()->where('site_id', $site->id)->where('status', 'done')->whereNotNull('after_image')
                ->latest('after_at')->first(['id', 'after_image', 'after_at']);
            if ($check !== null) {
                return ['url' => $site->url, 'image' => (string) $check->after_image, 'at' => $check->after_at ? CarbonImmutable::parse($check->after_at) : null];
            }
        }

        return null;
    }

    /**
     * @param Collection<int, SiteEvent> $events
     * @param Collection<string, PagespeedResult> $pagespeed
     * @return array<string, mixed>
     */
    /** @return array<string, mixed>|null */
    private static function analytics(Site $site, CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        try {
            return \App\Ocm\GoogleApi::report($site, $start, $end->min(CarbonImmutable::today()->subDay()));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function site(Site $site, Collection $events, Collection $pagespeed, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $inventory = $site->inventory ?? [];
        $names = [];
        foreach ((array) ($inventory['plugins'] ?? []) as $p) {
            $names['plugin:' . ($p['file'] ?? '')] = $p['name'] ?? $p['file'] ?? '';
        }
        foreach ((array) ($inventory['themes'] ?? []) as $t) {
            $names['theme:' . ($t['stylesheet'] ?? '')] = $t['name'] ?? $t['stylesheet'] ?? '';
        }

        $updates = $events->whereIn('type', self::UPDATE_TYPES)->map(function (SiteEvent $e) use ($names) {
            $kind = strtok($e->type, '.');
            $name = match ($kind) {
                'core' => 'WordPress',
                'translation' => 'Vertalingen (' . (explode(':', (string) $e->object)[1] ?? '') . ')',
                default => $names[$kind . ':' . $e->object] ?? $e->object,
            };

            return ['date' => $e->occurred_at, 'kind' => ['plugin' => 'Plugin', 'theme' => 'Thema', 'core' => 'WordPress', 'translation' => 'Vertaling'][$kind] ?? $kind,
                'name' => $name, 'from' => $e->from_version, 'to' => $e->to_version, 'ok' => $e->result !== 'failed'];
        })->values()->all();

        $failed = $events->where('type', 'login.failed_summary');
        $channels = [];
        foreach ($failed as $e) {
            foreach ((array) ($e->data['channels'] ?? []) as $channel => $n) {
                $channels[$channel] = ($channels[$channel] ?? 0) + (int) $n;
            }
        }
        $issues = collect($site->issues())->where('category', 'beveiliging')->whereIn('level', ['crit', 'warn'])->pluck('text')->values()->all();

        $psi = [];
        foreach (PagespeedResult::STRATEGIES as $strategy => $label) {
            $r = $pagespeed->get($strategy);
            if ($r) {
                $before = $site->pagespeedResults()->where('strategy', $strategy)->whereNull('error')->where('measured_at', '<', $start)->latest('measured_at')->value('performance');
                $psi[$strategy] = ['label' => $label, 'performance' => $r->performance, 'accessibility' => $r->accessibility, 'best_practices' => $r->best_practices,
                    'seo' => $r->seo, 'lcp_ms' => $r->lcp_ms, 'cls' => $r->cls, 'before' => $before, 'measured_at' => $r->measured_at];
            }
        }

        $changes = $events->filter(fn (SiteEvent $e) => isset(self::CHANGE_LABELS[$e->type]))->map(fn (SiteEvent $e) => [
            'date' => $e->occurred_at, 'what' => self::CHANGE_LABELS[$e->type], 'object' => $names[strtok($e->type, '.') . ':' . $e->object] ?? $e->object,
        ])->values()->take(40)->all();

        $login = $inventory['environment']['login_security'] ?? null;
        $uptime = \App\Ocm\Monitoring\Monitor::stats($site, $start, $end);
        $uptime['list'] = $uptime['list']->take(15)->map(fn (\App\Models\UptimeIncident $i) => [
            'start' => $i->started_at, 'end' => $i->ended_at, 'duration' => $i->durationLabel(), 'cause' => $i->cause,
        ])->values()->all();

        return [
            'name' => $site->name,
            'url' => $site->url,
            'wp' => $site->wp_version ?? ($inventory['core']['version'] ?? null),
            'php' => $site->php_version,
            'plugins' => count((array) ($inventory['plugins'] ?? [])),
            'themes' => count((array) ($inventory['themes'] ?? [])),
            'https' => $inventory['environment']['https'] ?? null,
            'uptime' => $uptime,
            'analytics' => self::analytics($site, $start, $end),
            'broken_links' => \App\Models\BrokenLink::query()->where('site_id', $site->id)->whereNull('ignored_at')->orderByDesc('internal')->limit(30)->get()
                ->map(fn ($l) => ['url' => $l->url, 'error' => $l->label(), 'page' => $l->found_on[0] ?? null, 'internal' => $l->internal])->all(),
            'ssl_expires_at' => $site->ssl_expires_at,
            'domain_expires_at' => $site->domain_expires_at,
            'updates' => $updates,
            'pending' => array_map(fn ($u) => ['kind' => ['core' => 'WordPress', 'plugin' => 'Plugin', 'theme' => 'Thema'][$u['type']], 'name' => $u['name'], 'from' => $u['from'], 'to' => $u['to']], $site->availableUpdates()),
            'security' => [
                'failed_logins' => (int) $failed->sum(fn (SiteEvent $e) => (int) ($e->data['count'] ?? 0)),
                'locked' => (int) $failed->sum(fn (SiteEvent $e) => (int) ($e->data['locked'] ?? 0)),
                'channels' => $channels,
                'issues' => $issues,
                'vulnerabilities' => count(\App\Ocm\Vulnerabilities::forSite($site)),
                'login_security' => is_array($login) ? $login : null,
            ],
            'pagespeed' => $psi,
            'changes' => $changes,
        ];
    }
}
