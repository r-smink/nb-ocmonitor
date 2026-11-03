<?php

namespace App\Ocm;

use App\Models\Backup;
use App\Models\Site;
use App\Models\SiteJob;
use App\Models\User;
use App\Models\VisualCheck;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Backup vlak vóór updates, opgeslagen in DigitalOcean Spaces (S3).
 *
 * Updates::queue houdt update-jobs vast en koppelt ze aan een backup. De site maakt de backup,
 * versleutelt hem met een sleutel die alleen op de site staat en uploadt hem via een tijdelijke
 * link. Pas daarna gaan de updates door. Mislukt de backup, dan worden de updates geweigerd.
 * Een backup blijft 7 dagen staan, of tot de visuele controle als "in orde" is gemarkeerd.
 */
final class Backups
{
    public const DISK = 'backups';

    public static function enabled(): bool
    {
        return (bool) config('ocm.backups.enabled')
            && (string) config('filesystems.disks.' . self::DISK . '.key') !== ''
            && (string) config('filesystems.disks.' . self::DISK . '.bucket') !== '';
    }

    public static function supported(Site $site): bool
    {
        return $site->plugin_version !== null && version_compare($site->plugin_version, '0.5.0', '>=');
    }

    /**
     * Neemt een update-job op in de backup die vóór die update gemaakt wordt. Zolang de backup-job
     * nog niet gestart is, groeit dezelfde backup mee; anders komt er een nieuwe.
     *
     * @param array{plugins?: string[], themes?: string[], core?: bool} $components
     */
    public static function supportsFull(Site $site): bool
    {
        return $site->plugin_version !== null && version_compare($site->plugin_version, '0.8.0', '>=');
    }

    /** Recente volledige backup (in de wachtrij of klaar) die updates kunnen gebruiken in plaats van een eigen backup. */
    public static function recentFull(Site $site): ?Backup
    {
        $hours = (int) config('ocm.backups.full_reuse_hours', 3);
        if ($hours <= 0) {
            return null;
        }

        return Backup::query()->where('site_id', $site->id)->whereIn('status', ['pending', 'ready'])
            ->where('created_at', '>=', now()->subHours($hours))->latest('id')->get()
            ->first(fn (Backup $b) => !empty($b->components['full']));
    }

    /** Volledige backup op verzoek: database, alle plugins en thema's, WordPress en de rest van wp-content. */
    public static function full(Site $site, ?User $user): Backup
    {
        $backup = Backup::query()->create([
            'backup_id' => 'bk_' . Str::lower(Str::random(20)),
            'site_id' => $site->id,
            'status' => 'pending',
            'components' => ['plugins' => [], 'themes' => [], 'core' => true, 'full' => true],
            'update_job_ids' => [],
            'woocommerce' => collect((array) (($site->inventory ?? [])['plugins'] ?? []))->contains(fn ($p) => ($p['file'] ?? '') === 'woocommerce/woocommerce.php'),
        ]);
        $backup->setRelation('site', $site);
        $job = JobQueue::enqueue($site, 'backup.create', self::createArgs($backup), $user, false);
        $backup->update(['job_id' => $job->id]);
        Activity::log('backup_full_requested', ['backup_id' => $backup->backup_id], $site, $user);

        return $backup;
    }

    public static function attach(Site $site, SiteJob $updateJob, array $components): Backup
    {
        // Is er net een volledige backup gemaakt (of bezig), dan die gebruiken: geen losse backup per update.
        if ($full = self::recentFull($site)) {
            $full->update(['update_job_ids' => array_values(array_unique(array_merge($full->update_job_ids ?? [], [$updateJob->id])))]);

            return $full;
        }
        $backup = Backup::query()->where('site_id', $site->id)->where('status', 'pending')
            ->whereHas('job', fn ($q) => $q->where('status', 'pending'))->latest('id')->first();

        if ($backup === null) {
            $backup = Backup::query()->create([
                'backup_id' => 'bk_' . Str::lower(Str::random(20)),
                'site_id' => $site->id,
                'status' => 'pending',
                'components' => ['plugins' => [], 'themes' => [], 'core' => false],
                'update_job_ids' => [],
                'woocommerce' => collect((array) (($site->inventory ?? [])['plugins'] ?? []))->contains(fn ($p) => ($p['file'] ?? '') === 'woocommerce/woocommerce.php'),
            ]);
            $backup->setRelation('site', $site);
            $job = JobQueue::enqueue($site, 'backup.create', [], $updateJob->requester, false, now()->addSeconds(15));
            $backup->update(['job_id' => $job->id]);
        }

        $current = $backup->components;
        $merged = [
            'plugins' => array_values(array_unique(array_merge($current['plugins'] ?? [], $components['plugins'] ?? []))),
            'themes' => array_values(array_unique(array_merge($current['themes'] ?? [], $components['themes'] ?? []))),
            'core' => !empty($current['core']) || !empty($components['core']),
        ];
        $backup->update(['components' => $merged, 'update_job_ids' => array_values(array_unique(array_merge($backup->update_job_ids ?? [], [$updateJob->id])))]);
        SiteJob::query()->whereKey($backup->job_id)->where('status', 'pending')->update(['args' => self::createArgs($backup->setRelation('site', $site))]);

        return $backup;
    }

    /** @return array<string, mixed> */
    private static function createArgs(Backup $backup): array
    {
        $minutes = (int) config('ocm.backups.link_minutes', 180);
        $links = [];
        foreach (['db', 'files'] as $part) {
            $link = Storage::disk(self::DISK)->temporaryUploadUrl($backup->path($part), now()->addMinutes($minutes));
            $links[$part] = ['url' => $link['url'], 'headers' => array_map(fn ($v) => is_array($v) ? implode(',', $v) : (string) $v, (array) ($link['headers'] ?? []))];
        }
        $c = $backup->components;

        return array_filter([
            'backup_id' => $backup->backup_id,
            'plugins' => $c['plugins'] ?? [],
            'themes' => $c['themes'] ?? [],
            'core' => !empty($c['core']),
            'full' => !empty($c['full']),
            'uploads' => $links,
        ], fn ($v) => $v !== [] && $v !== false);
    }

    /** @param array<string, mixed> $result */
    public static function created(SiteJob $job, Site $site, array $result): void
    {
        $backup = Backup::query()->where('job_id', $job->id)->first();
        if ($backup === null || ($result['backup_id'] ?? null) !== $backup->backup_id) {
            return;
        }
        $sha = fn ($v) => is_string($v) && preg_match('/^[a-f0-9]{64}$/', $v) ? $v : null;
        $backup->update([
            'status' => 'ready',
            'db_size' => (int) ($result['db_size'] ?? 0),
            'files_size' => (int) ($result['files_size'] ?? 0),
            'db_sha256' => $sha($result['db_sha256'] ?? null),
            'files_sha256' => $sha($result['files_sha256'] ?? null),
            'tables' => (int) ($result['db']['tables'] ?? 0),
            'expires_at' => now()->addDays((int) config('ocm.backups.keep_days', 7)),
        ]);
        // Tijdelijke links horen niet in het jobarchief.
        $job->update(['args' => ['backup_id' => $backup->backup_id]]);
        Updates::releaseReady($site);
    }

    /** @param array<string, mixed> $result */
    public static function restored(SiteJob $job, Site $site, array $result): void
    {
        $backup = Backup::query()->where('restore_job_id', $job->id)->first();
        if ($backup === null) {
            return;
        }
        $backup->update(['status' => 'ready', 'restored_at' => now(), 'error' => null]);
        $job->update(['args' => ['backup_id' => $backup->backup_id, 'parts' => ($job->args ?? [])['parts'] ?? []]]);
        Activity::log('backup_restored', ['backup_id' => $backup->backup_id, 'parts' => $result['parts'] ?? null], $site, $job->requester);
        JobQueue::enqueue($site, 'site.inventory', ['refresh' => true]);
    }

    /**
     * Backup terugzetten. Bij webshops alleen met $confirmDatabase ook de database, omdat
     * bestellingen van na de backup dan verloren gaan.
     *
     * @param string[] $parts
     */
    public static function restore(Backup $backup, array $parts, ?User $user): SiteJob
    {
        $backup->loadMissing('site');
        $minutes = (int) config('ocm.backups.link_minutes', 180);
        $downloads = [];
        $sha = [];
        foreach ($parts as $part) {
            $downloads[$part] = ['url' => Storage::disk(self::DISK)->temporaryUrl($backup->path($part), now()->addMinutes($minutes)), 'headers' => []];
            $sha[$part] = (string) $backup->{$part . '_sha256'};
        }
        $job = JobQueue::enqueue($backup->site, 'backup.restore', ['backup_id' => $backup->backup_id, 'parts' => $parts, 'downloads' => $downloads, 'sha256' => $sha], $user, false);
        $backup->update(['status' => 'restoring', 'restore_job_id' => $job->id, 'error' => null]);
        Activity::log('backup_restore_requested', ['backup_id' => $backup->backup_id, 'parts' => $parts], $backup->site, $user);

        return $job;
    }

    public static function delete(Backup $backup, string $reason): void
    {
        if (in_array($backup->status, ['deleted', 'restoring'], true)) {
            return;
        }
        $backup->loadMissing('site');
        Storage::disk(self::DISK)->delete([$backup->path('db'), $backup->path('files')]);
        $backup->update(['status' => $backup->status === 'failed' ? 'failed' : 'deleted', 'deleted_at' => now()]);
        Activity::log('backup_deleted', ['backup_id' => $backup->backup_id, 'reason' => $reason], $backup->site, null);
    }

    /** Na "in orde" bij de visuele controle: de bijbehorende backups direct weg. */
    public static function deleteForVisualCheck(VisualCheck $check): void
    {
        $jobs = $check->job_ids ?? [];
        Backup::query()->where('site_id', $check->site_id)->where('status', 'ready')->get()
            ->filter(fn (Backup $b) => empty($b->components['full']) && array_intersect($b->update_job_ids ?? [], $jobs) !== [])
            ->each(fn (Backup $b) => self::delete($b, 'visuele controle in orde'));
    }

    /** Elke minuut: mislukte backups afhandelen en verlopen backups opruimen. */
    public static function tick(): void
    {
        foreach (Backup::query()->with(['job', 'site'])->where('status', 'pending')->get() as $backup) {
            $job = $backup->job;
            $stuck = $backup->created_at->lt(now()->subMinutes((int) config('ocm.backups.max_minutes', 90)));
            if ($job === null || in_array($job->status, ['failed', 'rejected'], true) || $stuck) {
                $error = $job?->error ?? ($stuck ? 'Backup duurde te lang' : 'Backup-opdracht ontbreekt');
                $backup->update(['status' => 'failed', 'error' => mb_substr((string) $error, 0, 500)]);
                $job?->update(['args' => ['backup_id' => $backup->backup_id]]);
                SiteJob::query()->whereIn('id', $backup->update_job_ids ?? [])->where('status', 'pending')
                    ->update(['status' => 'rejected', 'error' => 'Geen update uitgevoerd: de backup vooraf is mislukt (' . mb_substr((string) $error, 0, 300) . ')', 'finished_at' => now()]);
                Activity::log('backup_failed', ['backup_id' => $backup->backup_id, 'error' => $error], $backup->site, null);
                Storage::disk(self::DISK)->delete([$backup->path('db'), $backup->path('files')]);
            }
        }
        foreach (Backup::query()->with(['site'])->where('status', 'restoring')->get() as $backup) {
            $job = SiteJob::query()->find($backup->restore_job_id);
            if ($job === null || in_array($job->status, ['failed', 'rejected'], true)) {
                $backup->update(['status' => 'ready', 'error' => 'Terugzetten mislukt: ' . mb_substr((string) ($job?->error ?? 'opdracht ontbreekt'), 0, 450)]);
                Activity::log('backup_restore_failed', ['backup_id' => $backup->backup_id, 'error' => $job?->error], $backup->site, null);
            }
        }
        foreach (Backup::query()->with('site')->where('status', 'ready')->where('expires_at', '<', now())->get() as $backup) {
            self::delete($backup, 'na ' . config('ocm.backups.keep_days', 7) . ' dagen');
        }
    }
}
