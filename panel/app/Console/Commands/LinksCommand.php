<?php

namespace App\Console\Commands;

use App\Models\LinkScan;
use App\Models\Site;
use App\Ocm\LinkChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ocm:links {--site=* : Alleen deze site-id(s)}')]
#[Description('Kapotte links en afbeeldingen zoeken (één site per run, aangevraagde sites eerst)')]
class LinksCommand extends Command
{
    public function handle(): int
    {
        $sites = $this->option('site') !== []
            ? Site::query()->whereIn('site_id', $this->option('site'))->get()
            : collect([$this->next()])->filter();
        foreach ($sites as $site) {
            $scan = LinkScan::query()->create(['site_id' => $site->id, 'status' => 'running', 'started_at' => now()]);
            try {
                $result = LinkChecker::scan($site);
                $scan->update($result + ['status' => 'done', 'finished_at' => now()]);
                $this->line("{$site->host}: {$result['pages']} pagina's, {$result['links']} links, {$result['broken']} kapot");
            } catch (\Throwable $e) {
                $scan->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'finished_at' => now()]);
                $this->error("{$site->host}: {$e->getMessage()}");
            } finally {
                $site->forceFill(['links_requested_at' => null])->save();
            }
        }

        return self::SUCCESS;
    }

    private function next(): ?Site
    {
        $requested = Site::query()->whereNotNull('public_key')->whereNotNull('links_requested_at')->orderBy('links_requested_at')->first();
        if ($requested) {
            return $requested;
        }
        $cutoff = now()->subDays((int) config('ocm.links.interval_days', 7));

        return Site::query()->whereNotNull('public_key')
            ->whereDoesntHave('linkScans', fn ($q) => $q->where('started_at', '>=', $cutoff))
            ->withMax('linkScans', 'started_at')->orderBy('link_scans_max_started_at')->first();
    }
}
