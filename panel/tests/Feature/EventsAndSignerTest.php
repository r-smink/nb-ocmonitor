<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\SiteJob;
use App\Ocm\Activity;
use App\Ocm\JobQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use NextBuzz\OcMonitor\Protocol\Canonical;
use NextBuzz\OcMonitor\Protocol\InMemoryNonceStore;
use NextBuzz\OcMonitor\Protocol\KeyPair;
use NextBuzz\OcMonitor\Protocol\Signer;
use NextBuzz\OcMonitor\Protocol\StaticKeyResolver;
use NextBuzz\OcMonitor\Protocol\Verifier;
use Tests\TestCase;

class EventsAndSignerTest extends TestCase
{
    use RefreshDatabase;

    private function event(int $seq, string $type = 'plugin.update'): array
    {
        return ['seq' => $seq, 'time' => time(), 'type' => $type, 'object' => 'akismet/akismet.php', 'from' => '5.3', 'to' => '5.4',
            'result' => 'success', 'source' => 'wp-admin', 'job_id' => null, 'user_id' => 1, 'user_login' => 'klant', 'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla', 'data' => []];
    }

    /** Zoals EventPusher in de plugin het verzoek bouwt. */
    private function pushEvents(Site $site, array $events, ?KeyPair $keys = null)
    {
        $path = '/api/sites/' . $site->site_id . '/events';
        $body = json_encode(['site_id' => $site->site_id, 'events' => $events], JSON_UNESCAPED_SLASHES);
        $headers = (new Signer($keys ?? $this->siteKeys))->signRequest(Canonical::CTX_SITE, 'POST', 'ocm.test', $path, $site->site_id, $body, time());
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return [$this->call('POST', $path, [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body), $headers, $body, $server];
    }

    public function test_signed_events_are_stored_and_replay_is_rejected(): void
    {
        $site = $this->makePairedSite();
        [$response, , $body, $server] = $this->pushEvents($site, [$this->event(1), $this->event(2)]);
        $response->assertOk()->assertJson(['ok' => true, 'received' => 2]);
        $this->assertSame(2, $site->fresh()->last_event_seq);
        $this->assertSame('wp-admin', SiteEvent::query()->first()->source);

        $this->call('POST', '/api/sites/' . $site->site_id . '/events', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body)->assertNotFound();
    }

    public function test_events_signed_with_other_key_are_rejected(): void
    {
        $site = $this->makePairedSite();
        [$response] = $this->pushEvents($site, [$this->event(1)], KeyPair::generate());
        $response->assertNotFound();
        $this->assertSame(0, SiteEvent::query()->count());
    }

    public function test_missing_events_trigger_a_pull(): void
    {
        $site = $this->makePairedSite();
        $this->pushEvents($site, [$this->event(1)]);
        [$response] = $this->pushEvents($site, [$this->event(3)]);
        $response->assertOk();
        $this->assertSame(1, $site->fresh()->last_event_seq);
        $this->assertTrue(SiteJob::query()->where('command', 'events.pull')->exists());
    }

    /**
     * Volledige rondgang door de signer met een nagebootste site die verifieert zoals de plugin
     * en ondertekend antwoordt.
     */
    public function test_signer_sends_signed_commands_and_processes_verified_results(): void
    {
        $site = $this->makePairedSite();
        $seen = [];
        Http::fake(function (HttpRequest $request) use ($site, &$seen) {
            $verified = (new Verifier(new StaticKeyResolver([$this->panelKeys->publicKey()]), new InMemoryNonceStore()))
                ->verifyRequest(Canonical::CTX_COMMAND, 'POST', $site->host, '/nb-ocm/v1/cmd', $site->site_id, $request->headers(), $request->body(), time());
            $command = json_decode($request->body(), true);
            $seen[] = $command['command'];

            $result = match ($command['command']) {
                'site.info' => ['wp_version' => '6.9', 'php_version' => '8.3.1', 'plugin_version' => '0.1.0', 'last_event_seq' => 2],
                'events.pull' => ['events' => [$this->event(1, 'plugin.activated'), $this->event(2, 'component.changed_externally')], 'last_seq' => 2, 'more' => false],
                default => ['acked' => 2],
            };
            $body = json_encode(['ok' => true, 'job_id' => $command['job_id'], 'result' => $result], JSON_UNESCAPED_SLASHES);
            $headers = (new Signer($this->siteKeys))->signResponse($site->site_id, $verified->nonce, 200, $body, time());

            return Http::response($body, 200, $headers);
        });

        JobQueue::enqueue($site, 'site.info');
        $this->artisan('ocm:signer --once')->assertSuccessful();

        $this->assertSame(['site.info', 'events.pull', 'events.ack'], $seen);
        $site->refresh();
        $this->assertSame('6.9', $site->wp_version);
        $this->assertSame(2, $site->last_event_seq);
        $this->assertSame(2, SiteEvent::query()->count());
        $this->assertSame(0, SiteJob::query()->where('status', '!=', 'done')->count());
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://klant.example.nl/?rest_route=/nb-ocm/v1/cmd'));
    }

    public function test_unsigned_response_fails_the_job(): void
    {
        $site = $this->makePairedSite();
        Http::fake(fn () => Http::response(json_encode(['ok' => true, 'result' => ['wp_version' => 'nep']]), 200));
        JobQueue::enqueue($site, 'site.info');
        $this->artisan('ocm:signer --once')->assertSuccessful();

        $job = SiteJob::query()->firstOrFail();
        $this->assertSame('failed', $job->status);
        $this->assertNull($site->fresh()->wp_version);
        $this->assertSame('error', $site->fresh()->status);
    }

    public function test_signer_rejects_commands_outside_allowlist(): void
    {
        $site = $this->makePairedSite();
        Http::fake();
        JobQueue::enqueue($site, 'user.create', ['role' => 'administrator']);
        $this->artisan('ocm:signer --once')->assertSuccessful();

        $this->assertSame('rejected', SiteJob::query()->firstOrFail()->status);
        Http::assertNothingSent();
    }

    public function test_signer_refuses_to_start_with_mismatched_key(): void
    {
        config(['ocm.signing_key' => KeyPair::generate()->secretKeyB64()]);
        $this->artisan('ocm:signer --once')->assertFailed();
    }

    public function test_activity_log_hash_chain_detects_tampering(): void
    {
        Activity::log('een');
        Activity::log('twee', ['x' => 1]);
        Activity::log('drie');
        $this->assertTrue(Activity::verifyChain()['ok']);

        \DB::table('activity_log')->where('action', 'twee')->update(['details' => json_encode(['x' => 2])]);
        $result = Activity::verifyChain();
        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['checked']);
    }
}
