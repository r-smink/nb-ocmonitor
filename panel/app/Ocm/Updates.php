<?php

namespace App\Ocm;

use App\Models\Site;
use App\Models\SiteJob;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Updates klaarzetten voor de signer. Wat er bijgewerkt mag worden komt altijd uit de laatste
 * inventaris van de site; wat de browser instuurt wordt daartegen gecontroleerd.
 */
final class Updates
{
    public const COMMANDS = [
        'plugin' => 'update.plugin',
        'theme' => 'update.theme',
        'core' => 'update.core',
        'translations' => 'update.translations',
        'client' => 'plugin.self_update',
        'cleanup' => 'db.cleanup',
    ];

    private const ARG_NAMES = ['plugin' => 'plugins', 'theme' => 'themes'];

    /**
     * Zet updates klaar voor één site. Onbekende componenten, componenten zonder update en
     * componenten die al in de wachtrij staan worden overgeslagen.
     *
     * @param string[] $ids pluginbestanden of stylesheets; genegeerd voor core/translations
     * @return int aantal componenten dat is klaargezet
     */
    public static function queue(Site $site, string $type, array $ids, ?User $user): int
    {
        if (!isset(self::COMMANDS[$type]) || $site->updatesBlockedReason() !== null) {
            return 0;
        }
        $command = self::COMMANDS[$type];

        if ($type === 'translations') {
            if ((int) (($site->inventory ?? [])['translations_pending'] ?? 0) === 0) {
                return 0;
            }
            $job = self::enqueue($site, $command, [], $user);
            Activity::log('updates_requested', ['job_id' => $job->job_id, 'type' => $type], $site, $user);

            return 1;
        }

        $available = collect($site->availableUpdates())->where('type', $type)->keyBy('id');
        if ($type === 'client') {
            $release = PluginRelease::offerFor($site->plugin_version);
            if ($release === null || in_array('nb-ocmonitor-client', self::queuedIds($site)['client'] ?? [], true)) {
                return 0;
            }
            $job = self::enqueue($site, $command, ['version' => $release['version'], 'sha256' => $release['sha256'], 'signature' => $release['signature']], $user, false);
            Activity::log('updates_requested', ['job_id' => $job->job_id, 'type' => $type, 'to' => $release['version']], $site, $user);

            return 1;
        }
        if ($type === 'core') {
            if (!$available->has('wordpress')) {
                return 0;
            }
            $job = self::enqueue($site, $command, [], $user);
            Activity::log('updates_requested', ['job_id' => $job->job_id, 'type' => $type, 'to' => $available['wordpress']['to']], $site, $user);

            return 1;
        }

        $queued = self::queuedIds($site)[$type] ?? [];
        $ids = collect($ids)->map(fn ($id) => (string) $id)->unique()
            ->filter(fn (string $id) => $available->has($id) && !in_array($id, $queued, true))
            ->values();

        foreach ($ids->chunk(max(1, min(10, (int) config('ocm.updates.chunk_size')))) as $chunk) {
            $job = self::enqueue($site, $command, [self::ARG_NAMES[$type] => $chunk->values()->all()], $user, false);
            Activity::log('updates_requested', [
                'job_id' => $job->job_id,
                'type' => $type,
                'items' => $chunk->map(fn ($id) => $id . ' ' . $available[$id]['from'] . ' → ' . $available[$id]['to'])->values()->all(),
            ], $site, $user);
        }

        return $ids->count();
    }

    /**
     * Update-job aanmaken. Met visuele controle wachten de jobs even tot de screenshot vooraf er is.
     *
     * @param array<string, mixed> $args
     */
    private static function enqueue(Site $site, string $command, array $args, ?User $user, bool $deduplicate = true): SiteJob
    {
        $visual = Visual::enabled();
        $backup = Backups::enabled() && Backups::supported($site);
        $visual = $visual && $command !== 'db.cleanup';
        $visualHold = $visual && !\App\Models\VisualCheck::query()->where('site_id', $site->id)->where('status', 'updating')->exists();
        // Met backup: vasthouden tot de backup er is (releaseReady). De dag is alleen een vangnet;
        // Backups::tick weigert de updates al veel eerder als de backup niet lukt.
        $until = $backup ? now()->addDay() : ($visualHold ? Visual::holdUntil() : null);
        $job = JobQueue::enqueue($site, $command, $args, $user, $deduplicate, $until);
        if ($job->status !== 'pending') {
            return $job;
        }
        if ($visual && $command !== 'db.cleanup') {
            Visual::attach($site, $job);
        }
        if ($backup) {
            Backups::attach($site, $job, match ($command) {
                'update.plugin' => ['plugins' => $args['plugins'] ?? []],
                'update.theme' => ['themes' => $args['themes'] ?? []],
                'update.core' => ['core' => true],
                'plugin.self_update' => ['plugins' => ['nb-ocmonitor-client/nb-ocmonitor-client.php']],
                default => [],
            });
        }

        return $job;
    }

    /**
     * Database opschonen, met (als dat aan staat) eerst een backup van de database.
     *
     * @param string[] $items
     */
    public static function queueCleanup(Site $site, array $items, ?User $user): SiteJob
    {
        $job = self::enqueue($site, 'db.cleanup', ['items' => array_values($items)], $user, false);
        Activity::log('cleanup_requested', ['job_id' => $job->job_id, 'items' => $items], $site, $user);

        return $job;
    }

    /**
     * Geeft vastgehouden update-jobs vrij zodra hun backup klaar is en de screenshot vooraf er is.
     */
    public static function releaseReady(Site $site): void
    {
        $held = SiteJob::query()->where('site_id', $site->id)->where('status', 'pending')
            ->whereIn('command', array_values(self::COMMANDS))->where('available_at', '>', now())->get(['id']);
        if ($held->isEmpty()) {
            return;
        }
        $waitingBackups = \App\Models\Backup::query()->where('site_id', $site->id)->where('status', 'pending')->get(['update_job_ids'])
            ->flatMap(fn ($b) => $b->update_job_ids ?? [])->all();
        $waitingVisual = \App\Models\VisualCheck::query()->where('site_id', $site->id)->where('status', 'before')->get(['job_ids'])
            ->flatMap(fn ($c) => $c->job_ids ?? [])->all();
        $ready = $held->pluck('id')->reject(fn ($id) => in_array($id, $waitingBackups, true) || in_array($id, $waitingVisual, true))->all();
        if ($ready !== []) {
            SiteJob::query()->whereIn('id', $ready)->update(['available_at' => now()]);
        }
    }

    /**
     * Componenten die al in een openstaande update-job zitten, per type.
     *
     * @return array<string, list<string>>
     */
    public static function queuedIds(Site $site): array
    {
        return self::openJobs(collect([$site]))[$site->id] ?? [];
    }

    /**
     * @param Collection<int, Site> $sites
     * @return array<int, array<string, list<string>>> site-id => type => ids ('wordpress' voor core)
     */
    public static function openJobs(Collection $sites): array
    {
        $types = array_flip(self::COMMANDS);
        $result = [];
        SiteJob::query()->whereIn('site_id', $sites->pluck('id'))
            ->whereIn('command', array_values(self::COMMANDS))
            ->whereIn('status', ['pending', 'running'])
            ->get(['site_id', 'command', 'args'])
            ->each(function (SiteJob $job) use (&$result, $types) {
                $type = $types[$job->command];
                $ids = match ($type) {
                    'core' => ['wordpress'],
                    'translations' => ['translations'],
                    'client' => ['nb-ocmonitor-client'],
                    'cleanup' => ['cleanup'],
                    default => array_values((array) (($job->args ?? [])[self::ARG_NAMES[$type]] ?? [])),
                };
                $result[$job->site_id][$type] = array_merge($result[$job->site_id][$type] ?? [], $ids);
            });

        return $result;
    }
}
