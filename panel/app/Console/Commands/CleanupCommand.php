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

        $old = SiteJob::query()->whereIn('status', ['done', 'failed', 'rejected'])->where('created_at', '<', now()->subDays(30))->delete();

        $expired = Site::query()->whereNull('public_key')->whereNotNull('pairing_secret')->where('pairing_expires_at', '<', now())
            ->update(['pairing_secret' => null]);

        $this->info("nonces: {$nonces}, vastgelopen jobs: {$stale}, oude jobs: {$old}, verlopen koppelcodes: {$expired}");

        return self::SUCCESS;
    }
}
