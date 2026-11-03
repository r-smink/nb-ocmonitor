<?php

namespace App\Ocm;

use App\Models\Site;
use App\Models\SiteJob;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * De web-app en scheduler zetten hier alleen opdrachten klaar. Ondertekenen en versturen doet
 * uitsluitend de signer (ocm:signer), die ook de policy controleert.
 */
final class JobQueue
{
    /**
     * @param array<string, mixed> $args
     */
    public static function enqueue(Site $site, string $command, array $args = [], ?User $requestedBy = null, bool $deduplicate = true, ?\DateTimeInterface $availableAt = null): SiteJob
    {
        if ($deduplicate) {
            $existing = SiteJob::query()
                ->where('site_id', $site->id)
                ->where('command', $command)
                ->whereIn('status', ['pending', 'running'])
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return SiteJob::query()->create([
            'job_id' => 'job_' . Str::lower(Str::random(24)),
            'site_id' => $site->id,
            'command' => $command,
            'args' => $args,
            'status' => 'pending',
            'requested_by' => $requestedBy?->id,
            'available_at' => $availableAt ?? now(),
        ]);
    }
}
