<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\Activity;
use App\Ocm\PanelKeys;
use App\Ocm\Signer\CommandSender;
use App\Ocm\Signer\ResultProcessor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * De enige plek waar de private panel-sleutel gebruikt wordt (plan §2.4). Pakt klaargezette jobs op,
 * controleert de policy, ondertekent, verstuurt en verwerkt het geverifieerde antwoord.
 */
#[Signature('ocm:signer {--once : Verwerk de wachtrij één keer en stop} {--max-runtime=3600 : Herstart na zoveel seconden (Docker start opnieuw)}')]
#[Description('Ondertekent en verstuurt opdrachten naar gekoppelde sites')]
class SignerCommand extends Command
{
    public function handle(ResultProcessor $processor): int
    {
        try {
            $sender = new CommandSender(PanelKeys::signingKeys());
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info('Signer gestart met panel-sleutel ' . PanelKeys::keyId());

        $stopAt = time() + (int) $this->option('max-runtime');
        $stop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$stop): void {
                $stop = true;
            });
        }

        while (!$stop && time() < $stopAt) {
            $job = $this->claimNextJob();
            if ($job === null) {
                if ($this->option('once')) {
                    break;
                }
                sleep(2);
                continue;
            }
            $this->runJob($job, $sender, $processor);
        }

        return self::SUCCESS;
    }

    private function claimNextJob(): ?SiteJob
    {
        $candidate = SiteJob::query()->where('status', 'pending')->where('available_at', '<=', now())->orderBy('id')->first();
        if ($candidate === null) {
            return null;
        }
        $claimed = SiteJob::query()->whereKey($candidate->id)->where('status', 'pending')
            ->update(['status' => 'running', 'started_at' => now()]);

        return $claimed === 1 ? $candidate->fresh() : null;
    }

    private function runJob(SiteJob $job, CommandSender $sender, ResultProcessor $processor): void
    {
        $site = Site::query()->find($job->site_id);
        $rejection = $this->policyRejection($job, $site);
        if ($rejection !== null) {
            $job->update(['status' => 'rejected', 'error' => $rejection, 'finished_at' => now()]);
            Activity::log('job_rejected', ['job_id' => $job->job_id, 'command' => $job->command, 'reason' => $rejection], $site);
            $this->warn("{$job->job_id} geweigerd: {$rejection}");
            return;
        }

        $result = $sender->send($job, $site);

        if ($result->ok) {
            try {
                $processor->process($job, $site, $result->result);
            } catch (\Throwable $e) {
                report($e);
            }
            $site->forceFill(['status' => 'online', 'last_seen_at' => now(), 'last_error' => null])->save();
            $job->update([
                'status' => 'done',
                'http_status' => $result->httpStatus,
                'result' => $this->storableResult($job->command, $result->result),
                'finished_at' => now(),
            ]);
            $this->line("{$job->job_id} {$job->command} → ok");
            return;
        }

        $site->forceFill([
            'status' => $result->unreachable ? 'unreachable' : 'error',
            'last_error' => mb_substr((string) $result->error, 0, 500),
        ])->save();
        $job->update(['status' => 'failed', 'http_status' => $result->httpStatus, 'error' => mb_substr((string) $result->error, 0, 1000), 'finished_at' => now()]);
        if ($job->requested_by !== null) {
            Activity::log('job_failed', ['job_id' => $job->job_id, 'command' => $job->command, 'error' => $result->error], $site);
        }
        $this->warn("{$job->job_id} {$job->command} → {$result->error}");
    }

    private function policyRejection(SiteJob $job, ?Site $site): ?string
    {
        if ($site === null || !$site->isPaired()) {
            return 'site bestaat niet of is niet gekoppeld';
        }
        if (!in_array($job->command, (array) config('ocm.signer.allowed_commands'), true)) {
            return 'command staat niet op de allowlist van de signer';
        }
        $recent = SiteJob::query()->where('site_id', $site->id)->where('started_at', '>=', now()->subMinute())->count();
        if ($recent > (int) config('ocm.signer.max_jobs_per_site_per_minute')) {
            return 'rate limit per site overschreden';
        }

        return null;
    }

    /**
     * Grote resultaten (inventaris, events) staan al in hun eigen tabellen; in de job alleen een samenvatting.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function storableResult(string $command, array $result): array
    {
        return match ($command) {
            'site.inventory' => ['plugins' => count((array) ($result['plugins'] ?? [])), 'themes' => count((array) ($result['themes'] ?? []))],
            'events.pull' => ['events' => count((array) ($result['events'] ?? [])), 'last_seq' => $result['last_seq'] ?? null],
            default => $result,
        };
    }
}
