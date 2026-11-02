<?php

namespace App\Ocm\Signer;

use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\Activity;
use App\Ocm\EventIngestor;
use App\Ocm\JobQueue;

/**
 * Verwerkt het (geverifieerde) resultaat van een command in de database.
 */
final class ResultProcessor
{
    public function __construct(private readonly EventIngestor $ingestor)
    {
    }

    /**
     * @param array<string, mixed> $result
     */
    public function process(SiteJob $job, Site $site, array $result): void
    {
        match ($job->command) {
            'site.info' => $this->info($site, $result),
            'site.inventory' => $this->inventory($site, $result),
            'events.pull' => $this->events($site, $job, $result),
            'update.plugin', 'update.theme', 'update.core', 'update.translations' => $this->updated($site, $job, $result),
            default => null,
        };
    }

    /**
     * Na een update is de update-transient op de site gewist; daarom een inventaris mét refresh.
     *
     * @param array<string, mixed> $result
     */
    private function updated(Site $site, SiteJob $job, array $result): void
    {
        JobQueue::enqueue($site, 'site.inventory', ['refresh' => true]);

        $problems = collect((array) ($result['items'] ?? []))
            ->filter(fn ($item) => is_array($item) && in_array($item['status'] ?? '', ['failed', 'rolled_back', 'health_failed'], true))
            ->map(fn ($item) => ['id' => $item['id'] ?? '', 'status' => $item['status'], 'message' => $item['message'] ?? null])
            ->values()->all();
        if ($problems !== []) {
            Activity::log('update_problem', ['job_id' => $job->job_id, 'command' => $job->command, 'items' => $problems], $site);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function info(Site $site, array $result): void
    {
        $site->wp_version = self::str($result['wp_version'] ?? null);
        $site->php_version = self::str($result['php_version'] ?? null);
        $site->plugin_version = self::str($result['plugin_version'] ?? null);
        if (isset($result['scopes']) && is_array($result['scopes'])) {
            $site->scopes = array_values(array_filter($result['scopes'], fn ($s) => is_string($s) && preg_match('/^[a-z_]{1,32}$/', $s)));
        }
        $site->save();

        $remoteSeq = (int) ($result['last_event_seq'] ?? 0);
        if ($remoteSeq > $site->last_event_seq) {
            JobQueue::enqueue($site, 'events.pull', ['after_seq' => (int) $site->last_event_seq]);
        } elseif ($remoteSeq < $site->last_event_seq) {
            // Volgnummers zijn teruggesprongen: eventtabel op de site geleegd of plugin opnieuw geïnstalleerd.
            $site->event_gap_at = now();
            $site->save();
            Activity::log('event_seq_reset', ['panel_seq' => $site->last_event_seq, 'site_seq' => $remoteSeq], $site);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function inventory(Site $site, array $result): void
    {
        $updates = 0;
        foreach (['plugins', 'themes'] as $kind) {
            foreach ((array) ($result[$kind] ?? []) as $item) {
                if (is_array($item) && !empty($item['update'])) {
                    $updates++;
                }
            }
        }
        if (!empty($result['core']['update'])) {
            $updates++;
        }

        $site->inventory = $result;
        $site->inventory_at = now();
        $site->updates_count = $updates;
        $site->wp_version = self::str($result['core']['version'] ?? $site->wp_version);
        $site->php_version = self::str($result['php_version'] ?? $site->php_version);
        $site->save();
    }

    /**
     * @param array<string, mixed> $result
     */
    private function events(Site $site, SiteJob $job, array $result): void
    {
        $after = (int) (($job->args ?? [])['after_seq'] ?? 0);
        $outcome = $this->ingestor->ingest($site, (array) ($result['events'] ?? []), $after);
        $site->refresh();

        if (!empty($result['more']) || $outcome['needs_pull']) {
            JobQueue::enqueue($site, 'events.pull', ['after_seq' => (int) $site->last_event_seq], null, false);
        } elseif ($site->last_event_seq > 0) {
            JobQueue::enqueue($site, 'events.ack', ['up_to_seq' => (int) $site->last_event_seq]);
        }
    }

    /**
     * @param mixed $value
     */
    private static function str($value): ?string
    {
        return is_scalar($value) ? mb_substr((string) $value, 0, 32) : null;
    }
}
