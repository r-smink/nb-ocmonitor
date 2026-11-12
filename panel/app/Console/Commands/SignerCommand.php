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

        // Back-up op de achtergrond: niet te veel tegelijk op dezelfde server, anders raakt die overbelast.
        if ($job->command === 'backup.create' && ($job->args['async'] ?? false) === true && \App\Ocm\Backups::serverBusy($site)) {
            $job->update(['status' => 'pending', 'started_at' => null, 'available_at' => now()->addMinute()]);
            $this->line("{$job->job_id} backup.create → wacht op een vrije plek op server " . ($site->server ?: '(onbekend)'));
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

        // Geen verbinding gekregen: de site heeft niets ontvangen, dus opnieuw proberen is veilig.
        $retries = (array) config('ocm.signer.connect_retry_minutes', []);
        if ($result->unreachable && self::neverConnected((string) $result->error) && $job->attempts < count($retries)) {
            $minutes = (int) $retries[$job->attempts];
            $job->update([
                'status' => 'pending', 'attempts' => $job->attempts + 1, 'started_at' => null,
                'available_at' => now()->addMinutes($minutes),
                'error' => mb_substr('Poging ' . ($job->attempts + 1) . ' mislukt, opnieuw over ' . $minutes . ' min: ' . $result->error, 0, 1000),
            ]);
            $site->forceFill(['last_error' => mb_substr((string) $result->error, 0, 500)])->save();
            $this->warn("{$job->job_id} {$job->command} → geen verbinding, opnieuw over {$minutes} min");
            return;
        }

        $site->forceFill([
            'status' => $result->unreachable ? 'unreachable' : 'error',
            'last_error' => mb_substr((string) $result->error, 0, 500),
        ])->save();
        $job->update(['status' => 'failed', 'http_status' => $result->httpStatus, 'error' => mb_substr((string) $result->error, 0, 1000), 'finished_at' => now()]);
        // Oudere plugins vervangen hun eigen bestanden midden in het verzoek en sturen dan soms geen
        // geldig antwoord meer, terwijl de update wel gelukt is. De heartbeat laat zien welke versie er nu staat.
        if ($job->command === 'plugin.self_update' && !$result->unreachable) {
            \App\Ocm\JobQueue::enqueue($site, 'site.info', [], null, true, now()->addMinute());
        }
        if ($job->requested_by !== null) {
            Activity::log('job_failed', ['job_id' => $job->job_id, 'command' => $job->command, 'error' => $result->error], $site);
        }
        $this->warn("{$job->job_id} {$job->command} → {$result->error}");
    }

    /**
     * Fouten van vóór de verbinding (DNS, verbinding geweigerd, connect-timeout). Een timeout tijdens
     * het wachten op antwoord ("Operation timed out") telt niet: dan kan de site de opdracht al uitvoeren.
     */
    public static function neverConnected(string $error): bool
    {
        return preg_match('/cURL error (6|7):|cURL error 28: (Connection timed out after|Resolving timed out after)/', $error) === 1;
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
