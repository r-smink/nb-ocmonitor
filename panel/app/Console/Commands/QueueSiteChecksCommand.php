<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\JobQueue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ocm:queue-checks {--inventory : Ook een volledige inventaris (met update-check) inplannen}')]
#[Description('Zet heartbeats (site.info) en optioneel inventarissen klaar voor de signer')]
class QueueSiteChecksCommand extends Command
{
    public function handle(): int
    {
        $interval = (int) config('ocm.heartbeat_minutes');
        $queued = 0;

        foreach (Site::query()->whereNotNull('public_key')->cursor() as $site) {
            if ($this->option('inventory')) {
                JobQueue::enqueue($site, 'site.inventory', ['refresh' => true]);
                $queued++;
                continue;
            }
            $recent = SiteJob::query()->where('site_id', $site->id)->where('command', 'site.info')
                ->where('created_at', '>=', now()->subMinutes($interval - 1))->exists();
            if (!$recent) {
                JobQueue::enqueue($site, 'site.info');
                $queued++;
            }
        }

        $this->info("{$queued} opdracht(en) klaargezet");

        return self::SUCCESS;
    }
}
