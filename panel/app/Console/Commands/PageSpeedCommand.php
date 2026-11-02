<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Ocm\PageSpeed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('ocm:pagespeed {--site=* : Alleen deze site-id(s), ongeacht wanneer ze gemeten zijn} {--limit= : Maximaal aantal sites in deze run}')]
#[Description('Meet PageSpeed (mobiel en desktop) voor aangevraagde sites en sites waarvan de meting verlopen is')]
class PageSpeedCommand extends Command
{
    public function handle(): int
    {
        $sites = $this->sitesToMeasure();
        foreach ($sites as $site) {
            $results = PageSpeed::measure($site);
            $site->forceFill(['pagespeed_requested_at' => null])->save();
            $this->line($site->host . ': ' . collect($results)
                ->map(fn ($r) => $r->strategy . ' ' . ($r->error !== null ? 'fout (' . $r->error . ')' : $r->performance))
                ->implode(', '));
        }
        $this->info($sites->count() . ' site(s) gemeten');

        return self::SUCCESS;
    }

    /**
     * Eerst sites waar iemand "Nu meten" koos, daarna gekoppelde sites zonder recente meting
     * (nooit gemeten eerst). Na een fout pas na een dag opnieuw, om het quotum te sparen.
     *
     * @return Collection<int, Site>
     */
    private function sitesToMeasure(): Collection
    {
        if ($this->option('site') !== []) {
            return Site::query()->whereIn('site_id', $this->option('site'))->get();
        }
        $limit = max(1, (int) ($this->option('limit') ?: config('ocm.pagespeed.sites_per_run')));

        $requested = Site::query()->whereNotNull('pagespeed_requested_at')->orderBy('pagespeed_requested_at')->limit($limit)->get();
        if ($requested->count() >= $limit) {
            return $requested;
        }

        $cutoff = now()->subDays((int) config('ocm.pagespeed.interval_days'))->addHour();
        $due = Site::query()
            ->whereNotNull('public_key')
            ->whereNull('pagespeed_requested_at')
            ->whereDoesntHave('pagespeedResults', fn ($q) => $q->whereNull('error')->where('measured_at', '>=', $cutoff))
            ->whereDoesntHave('pagespeedResults', fn ($q) => $q->whereNotNull('error')->where('measured_at', '>=', now()->subDay()))
            ->withMax('pagespeedResults', 'measured_at')
            ->orderBy('pagespeed_results_max_measured_at')
            ->limit($limit - $requested->count())
            ->get();

        return $requested->concat($due);
    }
}
