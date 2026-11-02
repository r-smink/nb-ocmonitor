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
            $job = JobQueue::enqueue($site, $command, [], $user);
            Activity::log('updates_requested', ['job_id' => $job->job_id, 'type' => $type], $site, $user);

            return 1;
        }

        $available = collect($site->availableUpdates())->where('type', $type)->keyBy('id');
        if ($type === 'core') {
            if (!$available->has('wordpress')) {
                return 0;
            }
            $job = JobQueue::enqueue($site, $command, [], $user);
            Activity::log('updates_requested', ['job_id' => $job->job_id, 'type' => $type, 'to' => $available['wordpress']['to']], $site, $user);

            return 1;
        }

        $queued = self::queuedIds($site)[$type] ?? [];
        $ids = collect($ids)->map(fn ($id) => (string) $id)->unique()
            ->filter(fn (string $id) => $available->has($id) && !in_array($id, $queued, true))
            ->values();

        foreach ($ids->chunk(max(1, min(10, (int) config('ocm.updates.chunk_size')))) as $chunk) {
            $job = JobQueue::enqueue($site, $command, [self::ARG_NAMES[$type] => $chunk->values()->all()], $user, false);
            Activity::log('updates_requested', [
                'job_id' => $job->job_id,
                'type' => $type,
                'items' => $chunk->map(fn ($id) => $id . ' ' . $available[$id]['from'] . ' → ' . $available[$id]['to'])->values()->all(),
            ], $site, $user);
        }

        return $ids->count();
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
                    default => array_values((array) (($job->args ?? [])[self::ARG_NAMES[$type]] ?? [])),
                };
                $result[$job->site_id][$type] = array_merge($result[$job->site_id][$type] ?? [], $ids);
            });

        return $result;
    }
}
