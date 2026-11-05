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
            'update.plugin', 'update.theme', 'update.core', 'update.translations', 'plugin.self_update' => $this->updated($site, $job, $result),
            'users.list' => $this->users($site, $result),
            'db.cleanup' => $this->cleaned($site, $job, $result),
            'license.activate' => $this->licensed($site, $job, $result),
            'tweaks.set' => $this->tweaked($site, $result),
            'backup.create' => \App\Ocm\Backups::created($job, $site, $result),
            'backup.restore' => \App\Ocm\Backups::restored($job, $site, $result),
            'users.lock', 'users.unlock', 'users.logout', 'users.reset_password', 'users.demote', 'security.two_factor' => $this->userAction($site, $job, $result),
            'security.integrity' => $site->forceFill(['integrity' => $result, 'integrity_at' => now()])->save(),
            // Na plugin-beheer of de onderhoudsmodus: inventaris bijwerken (zonder update-check bij wordpress.org).
            'plugin.install', 'plugin.activate', 'plugin.deactivate', 'plugin.delete', 'site.maintenance' => JobQueue::enqueue($site, 'site.inventory'),
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
     * Alleen bekende velden bewaren, ingekort; de lijst komt van de site en is dus niet te vertrouwen.
     *
     * @param array<string, mixed> $result
     */
    private function users(Site $site, array $result): void
    {
        $text = fn ($v, int $max = 190) => is_scalar($v) ? mb_substr((string) $v, 0, $max) : '';
        $users = collect((array) ($result['users'] ?? []))->filter(fn ($u) => is_array($u))->take(500)->map(fn (array $u) => [
            'id' => (int) ($u['id'] ?? 0),
            'login' => $text($u['login'] ?? '', 60),
            'email' => $text($u['email'] ?? ''),
            'name' => $text($u['name'] ?? '', 120),
            'roles' => array_values(array_filter(array_map(fn ($r) => is_string($r) && preg_match('/^[a-z0-9_-]{1,40}$/', $r) ? $r : null, (array) ($u['roles'] ?? [])))),
            'registered' => is_int($u['registered'] ?? null) ? $u['registered'] : null,
            'last_login' => is_int($u['last_login'] ?? null) ? $u['last_login'] : null,
            'locked' => !empty($u['locked']),
            'two_factor' => is_string($u['two_factor'] ?? null) ? $text($u['two_factor'], 30) : null,
            'sessions' => (int) ($u['sessions'] ?? 0),
        ])->filter(fn ($u) => $u['id'] > 0)->values()->all();
        $mode = (string) ($result['two_factor_mode'] ?? 'off');

        $site->wp_users = [
            'users' => $users,
            'total' => (int) ($result['total'] ?? count($users)),
            'two_factor_mode' => in_array($mode, ['off', 'admins', 'editors'], true) ? $mode : 'off',
            'two_factor_disabled' => !empty($result['two_factor_disabled_by_constant']),
        ];
        $site->wp_users_at = now();
        $site->save();
    }

    /** @param array<string, mixed> $result */
    private function tweaked(Site $site, array $result): void
    {
        if (is_array($result['tweaks'] ?? null)) {
            $inventory = $site->inventory ?? [];
            $inventory['tweaks'] = $result['tweaks'];
            $site->inventory = $inventory;
            $site->save();
        }
    }

    /** @param array<string, mixed> $result */
    private function licensed(Site $site, SiteJob $job, array $result): void
    {
        // De sleutel hoort niet in het jobarchief.
        $job->update(['args' => ['adapter' => ($job->args ?? [])['adapter'] ?? null]]);
        Activity::log('license_activated', ['job_id' => $job->job_id, 'adapter' => $result['adapter'] ?? null, 'active' => $result['active'] ?? null], $site, $job->requester);
        JobQueue::enqueue($site, 'site.inventory', ['refresh' => true]);
    }

    /** @param array<string, mixed> $result */
    private function cleaned(Site $site, SiteJob $job, array $result): void
    {
        $deleted = array_map('intval', array_filter((array) ($result['deleted'] ?? []), 'is_numeric'));
        Activity::log('cleanup_done', ['job_id' => $job->job_id, 'deleted' => $deleted, 'more' => !empty($result['more'])], $site, $job->requester);
        if (is_array($result['stats'] ?? null)) {
            $inventory = $site->inventory ?? [];
            $inventory['cleanup'] = $result['stats'];
            $site->inventory = $inventory;
            $site->save();
        }
    }

    /** @param array<string, mixed> $result */
    private function userAction(Site $site, SiteJob $job, array $result): void
    {
        Activity::log('wp_user_action_done', ['job_id' => $job->job_id, 'command' => $job->command, 'args' => $job->args], $site, $job->requester);
        JobQueue::enqueue($site, 'users.list');
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
