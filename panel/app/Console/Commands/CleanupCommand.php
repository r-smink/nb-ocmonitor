<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\DbNonceStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ocm:cleanup')]
#[Description('Ruimt nonces, oude jobs en verlopen koppelcodes op')]
class CleanupCommand extends Command
{
    public function handle(): int
    {
        $nonces = DbNonceStore::cleanup();

        // Jobs die bij een crash van de signer op "running" bleven staan.
        $stale = SiteJob::query()->where('status', 'running')->where('started_at', '<', now()->subMinutes(15))
            ->update(['status' => 'failed', 'error' => 'Signer gestopt tijdens uitvoering', 'finished_at' => now()]);

        \App\Models\UptimeCheck::query()->where('checked_at', '<', now()->subDays((int) config('ocm.monitoring.keep_days', 120)))->delete();
        \App\Models\VisualCheck::query()->where('created_at', '<', now()->subDays(90))->delete();
        // Licentiesleutels uit mislukte of geweigerde activeringen weghalen.
        SiteJob::query()->where('command', 'license.activate')->whereIn('status', ['failed', 'rejected', 'done'])->get()
            ->filter(fn (SiteJob $j) => isset(($j->args ?? [])['key']))
            ->each(fn (SiteJob $j) => $j->update(['args' => ['adapter' => ($j->args ?? [])['adapter'] ?? null]]));
        $old = SiteJob::query()->whereIn('status', ['done', 'failed', 'rejected'])->where('created_at', '<', now()->subDays(30))->delete();

        $expired = Site::query()->whereNull('public_key')->whereNotNull('pairing_secret')->where('pairing_expires_at', '<', now())
            ->update(['pairing_secret' => null]);

        $this->info("nonces: {$nonces}, vastgelopen jobs: {$stale}, oude jobs: {$old}, verlopen koppelcodes: {$expired}");

        return self::SUCCESS;
    }
}
