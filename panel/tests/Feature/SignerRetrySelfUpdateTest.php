<?php

namespace Tests\Feature;

use App\Console\Commands\SignerCommand;
use App\Models\Backup;
use App\Models\SiteJob;
use App\Ocm\JobQueue;
use App\Ocm\Updates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SignerRetrySelfUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_connect_phase_errors_count_as_never_connected(): void
    {
        $this->assertTrue(SignerCommand::neverConnected('Verbinding mislukt: cURL error 28: Connection timed out after 10001 milliseconds (see https://curl.se)'));
        $this->assertTrue(SignerCommand::neverConnected('Verbinding mislukt: cURL error 7: Failed to connect to x port 443'));
        $this->assertTrue(SignerCommand::neverConnected('Verbinding mislukt: cURL error 6: Could not resolve host: x'));
        $this->assertFalse(SignerCommand::neverConnected('Verbinding mislukt: cURL error 28: Operation timed out after 600000 milliseconds with 0 bytes received'));
    }

    public function test_connect_timeout_is_retried_with_backoff_then_fails(): void
    {
        $site = $this->makePairedSite();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out after 10001 milliseconds'));
        $job = JobQueue::enqueue($site, 'site.info');

        $this->artisan('ocm:signer --once')->assertSuccessful();
        $job->refresh();
        $this->assertSame('pending', $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertTrue($job->available_at->gt(now()->addSeconds(50)));
        $this->assertNotSame('unreachable', $site->fresh()->status);

        $retries = config('ocm.signer.connect_retry_minutes');
        for ($attempt = 2; $attempt <= count($retries); $attempt++) {
            $this->travel($retries[$attempt - 2] + 1)->minutes();
            $this->artisan('ocm:signer --once')->assertSuccessful();
            $this->assertSame($attempt, $job->fresh()->attempts);
        }
        $this->travel(end($retries) + 1)->minutes();
        $this->artisan('ocm:signer --once')->assertSuccessful();
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('unreachable', $site->fresh()->status);
    }

    public function test_read_timeout_is_not_retried(): void
    {
        $site = $this->makePairedSite();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 600000 milliseconds with 0 bytes received'));
        $job = JobQueue::enqueue($site, 'update.plugin', ['plugins' => ['a/a.php']]);
        $this->artisan('ocm:signer --once')->assertSuccessful();
        $this->assertSame('failed', $job->fresh()->status);
    }

    public function test_self_update_skips_backup_and_is_confirmed_by_the_next_heartbeat(): void
    {
        config(['ocm.backups.enabled' => true, 'filesystems.disks.backups.key' => 'k', 'filesystems.disks.backups.bucket' => 'b', 'ocm.visual.enabled' => false]);
        $site = $this->makePairedSite();
        $dist = storage_path('framework/testing/dist-retry');
        @mkdir($dist, 0777, true);
        file_put_contents($dist . '/nb-ocmonitor-client-0.13.0.zip', 'PK-zip');
        file_put_contents($dist . '/latest.json', json_encode(['version' => '0.13.0', 'file' => 'nb-ocmonitor-client-0.13.0.zip', 'sha256' => hash('sha256', 'PK-zip'), 'signature' => str_repeat('A', 86)]));
        config(['ocm.client_plugin_dist' => $dist]);
        $site->forceFill(['plugin_version' => '0.12.0'])->save();

        $this->assertSame(1, Updates::queue($site, 'client', [], null));
        $job = SiteJob::query()->where('command', 'plugin.self_update')->firstOrFail();
        $this->assertSame(0, Backup::query()->count());
        $this->assertTrue($job->available_at->lte(now()));

        // De site vervangt zijn eigen bestanden en stuurt een kapot antwoord (WordPress-foutpagina).
        Http::fake(fn () => Http::response('<html>Er is een kritieke fout</html>', 500));
        $this->artisan('ocm:signer --once')->assertSuccessful();
        $this->assertSame('failed', $job->fresh()->status);
        $info = SiteJob::query()->where('command', 'site.info')->where('status', 'pending')->firstOrFail();

        // De heartbeat meldt de nieuwe versie: de update is alsnog gelukt.
        app(\App\Ocm\Signer\ResultProcessor::class)->process($info, $site->fresh(), ['wp_version' => '6.8.3', 'php_version' => '8.3.1', 'plugin_version' => $job->args['version'], 'last_event_seq' => 0]);
        $job->refresh();
        $this->assertSame('done', $job->status);
        $this->assertNull($job->error);
    }
}
