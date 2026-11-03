<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Ocm\Monitoring\Monitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ocm:monitor {--site=* : Alleen deze site-id(s)}')]
#[Description('Uptime van alle sites; SSL dagelijks en de domeinregistratie wekelijks')]
class MonitorCommand extends Command
{
    public function handle(): int
    {
        $sites = Site::query()->when($this->option('site') !== [], fn ($q) => $q->whereIn('site_id', $this->option('site')))->get();
        foreach ($sites->chunk(25) as $chunk) {
            Monitor::checkUptime($chunk);
        }
        foreach ($sites as $site) {
            if ($site->ssl_checked_at === null || $site->ssl_checked_at->lt(now()->subHours(12))) {
                Monitor::checkSsl($site);
            }
            if ($site->domain_checked_at === null || $site->domain_checked_at->lt(now()->subDays(7))) {
                Monitor::checkDomain($site);
            }
        }
        $down = $sites->fresh()->where('uptime_state', 'down')->count();
        $this->info($sites->count() . ' site(s) gecontroleerd, ' . $down . ' offline');

        return self::SUCCESS;
    }
}
