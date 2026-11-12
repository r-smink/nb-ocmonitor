<?php

namespace App\Ocm;

use App\Models\Backup;
use App\Models\Site;
use App\Models\SiteJob;
use App\Models\VisualCheck;
use Illuminate\Support\Collection;

/**
 * Overzicht van de update-wachtrij: alles wat nog loopt plus wat recent klaar is, per klant en
 * per site, met per opdracht de fase waarin hij zit (backup, screenshot, wachtrij, bezig, klaar).
 */
final class UpdateQueue
{
    /** Fases in volgorde; 'done'/'partial'/'failed' tellen als afgerond. */
    public const PHASES = [
        'backup_wait' => 'wacht op backup',
        'backup' => 'backup wordt gemaakt',
        'screenshot' => 'screenshot vooraf',
        'held' => 'vastgehouden',
        'queued' => 'in wachtrij',
        'running' => 'bezig',
        'done' => 'klaar',
        'partial' => 'deels mislukt',
        'failed' => 'mislukt',
    ];

    private const ITEM_OK = ['updated', 'up_to_date'];

    /**
     * @return array{totals: array<string, int>, groups: Collection<int, array<string, mixed>>, open: bool}
     */
    public static function overview(int $hours = 24, ?int $customerId = null): array
    {
        $commands = array_values(Updates::COMMANDS);
        $jobs = SiteJob::query()->with(['site.customer', 'requester'])
            ->whereIn('command', $commands)
            ->where(fn ($q) => $q->whereIn('status', ['pending', 'running'])->orWhere('created_at', '>=', now()->subHours($hours)))
            ->orderBy('id')
            ->get()
            ->filter(fn (SiteJob $j) => $j->site !== null && ($customerId === null || $j->site->customer_id === $customerId));

        $siteIds = $jobs->pluck('site_id')->unique()->values();
        $backups = Backup::query()->with('job:id,status')->whereIn('site_id', $siteIds)->where('status', 'pending')->get();
        $visualBefore = VisualCheck::query()->whereIn('site_id', $siteIds)->where('status', 'before')->get(['site_id', 'job_ids']);
        $visualChecks = VisualCheck::query()->whereIn('site_id', $siteIds)->where('created_at', '>=', now()->subHours($hours + 24))
            ->latest('id')->get(['id', 'site_id', 'status', 'diff_percent', 'reviewed_at', 'job_ids', 'error'])->groupBy('site_id');
        $schedules = \App\Models\UpdateSchedule::query()->whereIn('id', $jobs->pluck('update_schedule_id')->filter()->unique())->pluck('name', 'id');

        $rows = $jobs->map(function (SiteJob $job) use ($backups, $visualBefore, $schedules) {
            $items = self::items($job);
            $phase = self::phase($job, $items, $backups, $visualBefore);

            return [
                'job' => $job,
                'phase' => $phase,
                'label' => self::label($job),
                'items' => $items,
                'total' => count($items),
                'ok' => count(array_filter($items, fn ($i) => $i['state'] === 'ok')),
                'bad' => count(array_filter($items, fn ($i) => $i['state'] === 'bad')),
                'schedule' => $job->update_schedule_id ? ($schedules[$job->update_schedule_id] ?? 'automatisch') : null,
            ];
        });

        $removable = Backups::removable(Site::query()->whereIn('id', $siteIds)->get(['id']))->countBy('site_id');
        $sites = $rows->groupBy(fn ($r) => $r['job']->site_id)->map(function (Collection $siteRows) use ($visualChecks, $removable) {
            /** @var Site $site */
            $site = $siteRows->first()['job']->site;
            $jobIds = $siteRows->pluck('job.id')->all();
            $visual = ($visualChecks[$site->id] ?? collect())->first(fn (VisualCheck $c) => array_intersect($c->job_ids ?? [], $jobIds) !== []);

            return ['site' => $site, 'rows' => $siteRows->values(), 'visual' => $visual, 'backups' => (int) ($removable[$site->id] ?? 0)] + self::count($siteRows);
        });

        $groups = $sites->groupBy(fn ($s) => $s['site']->customer_id ?? 0)
            ->map(fn (Collection $customerSites) => [
                'customer' => $customerSites->first()['site']->customer,
                'backups' => (int) $customerSites->sum('backups'),
                'sites' => $customerSites->sortBy(fn ($s) => [$s['open'] > 0 ? 0 : 1, mb_strtolower($s['site']->name)])->values(),
            ] + self::count($customerSites->flatMap(fn ($s) => $s['rows'])))
            ->sortBy(fn ($g) => [$g['open'] > 0 ? 0 : 1, $g['customer'] === null ? 1 : 0, mb_strtolower($g['customer']?->name ?? '')])
            ->values();

        $totals = self::count($rows) + ['sites' => $sites->count(), 'customers' => $groups->whereNotNull('customer')->count()];
        foreach (array_keys(self::PHASES) as $phase) {
            $totals['phase_' . $phase] = $rows->where('phase', $phase)->count();
        }

        return ['totals' => $totals, 'groups' => $groups, 'open' => $totals['open'] > 0];
    }

    /**
     * Voortgang over componenten: klaar, mislukt, bezig en nog wachtend.
     *
     * @param Collection<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private static function count(Collection $rows): array
    {
        $total = (int) $rows->sum('total');
        $ok = (int) $rows->sum('ok');
        $bad = (int) $rows->sum('bad');
        $running = (int) $rows->where('phase', 'running')->sum('total');
        $open = $rows->filter(fn ($r) => !in_array($r['phase'], ['done', 'partial', 'failed'], true))->count();

        return [
            'total' => $total,
            'ok' => $ok,
            'bad' => $bad,
            'running' => $running,
            'waiting' => max(0, $total - $ok - $bad - $running),
            'open' => $open,
            'jobs' => $rows->count(),
            'percent' => $total > 0 ? (int) floor(($ok + $bad) * 100 / $total) : 100,
        ];
    }

    /**
     * Componenten in een opdracht, met (als hij klaar is) de uitkomst per component.
     *
     * @return list<array{id: string, name: string, from: ?string, to: ?string, state: string, status: ?string, message: ?string}>
     */
    private static function items(SiteJob $job): array
    {
        $type = array_search($job->command, Updates::COMMANDS, true);
        $args = $job->args ?? [];
        $ids = match ($type) {
            'plugin' => array_values((array) ($args['plugins'] ?? [])),
            'theme' => array_values((array) ($args['themes'] ?? [])),
            'core' => ['wordpress'],
            'translations' => ['translations'],
            'client' => ['nb-ocmonitor-client'],
            default => ['cleanup'],
        };

        $inventory = $job->site->inventory ?? [];
        $names = collect($inventory['plugins'] ?? [])->mapWithKeys(fn ($p) => [(string) ($p['file'] ?? '') => (string) ($p['name'] ?? '')])
            ->merge(collect($inventory['themes'] ?? [])->mapWithKeys(fn ($t) => [(string) ($t['stylesheet'] ?? '') => (string) ($t['name'] ?? '')]))
            ->merge(['wordpress' => 'WordPress', 'translations' => 'Vertalingen', 'nb-ocmonitor-client' => 'OC-plugin', 'cleanup' => 'Database opschonen'])
            ->filter();
        $results = collect(is_array($job->result) ? ($job->result['items'] ?? []) : [])->filter(fn ($i) => is_array($i))->keyBy(fn ($i) => (string) ($i['id'] ?? ''));

        return array_map(function (string $id) use ($job, $names, $results, $args) {
            $result = $results[$id] ?? null;
            $status = $result['status'] ?? null;
            $state = match (true) {
                in_array($job->status, ['failed', 'rejected'], true) => 'bad',
                $job->status !== 'done' => $job->status === 'running' ? 'running' : 'waiting',
                $status === null => 'ok', // core, vertalingen, OC-plugin: geen uitkomst per component
                in_array($status, self::ITEM_OK, true) => 'ok',
                default => 'bad',
            };

            return [
                'id' => $id,
                'name' => $names[$id] ?? $id,
                'from' => $result['from'] ?? null,
                'to' => $result['to'] ?? ($id === 'nb-ocmonitor-client' ? ($args['version'] ?? null) : null),
                'state' => $state,
                'status' => $status,
                'message' => isset($result['message']) ? mb_substr((string) $result['message'], 0, 200) : null,
            ];
        }, $ids);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param Collection<int, Backup> $backups
     * @param Collection<int, VisualCheck> $visualBefore
     */
    private static function phase(SiteJob $job, array $items, Collection $backups, Collection $visualBefore): string
    {
        if (in_array($job->status, ['failed', 'rejected'], true)) {
            return 'failed';
        }
        if ($job->status === 'done') {
            return collect($items)->contains('state', 'bad') ? 'partial' : 'done';
        }
        if ($job->status === 'running') {
            return 'running';
        }
        if ($job->available_at === null || $job->available_at->lte(now())) {
            return 'queued';
        }
        $backup = $backups->first(fn (Backup $b) => $b->site_id === $job->site_id && in_array($job->id, $b->update_job_ids ?? [], true));
        if ($backup !== null) {
            // Een backup op de achtergrond: de opdracht is al klaar ("geaccepteerd"), de site werkt nog.
            return in_array($backup->job?->status, ['running', 'done'], true) ? 'backup' : 'backup_wait';
        }
        if ($visualBefore->contains(fn (VisualCheck $c) => $c->site_id === $job->site_id && in_array($job->id, $c->job_ids ?? [], true))) {
            return 'screenshot';
        }

        return 'held';
    }

    private static function label(SiteJob $job): string
    {
        return [
            'update.plugin' => 'Plugins',
            'update.theme' => 'Thema\'s',
            'update.core' => 'WordPress',
            'update.translations' => 'Vertalingen',
            'plugin.self_update' => 'OC-plugin',
            'db.cleanup' => 'Database opschonen',
        ][$job->command] ?? $job->command;
    }
}
