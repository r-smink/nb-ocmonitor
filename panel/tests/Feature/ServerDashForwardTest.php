<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Ocm\Alerts\Channels;
use App\Ocm\Alerts\ServerDash;
use App\Ocm\EventIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SMTP-events van de sites doorsturen naar ServerDash (Instellingen → Meldingen). */
class ServerDashForwardTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://dash.example.org/hooks/mail/tok_abcdef123456';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 12:00:00');
        Channels::$httpGuard = false;
    }

    protected function tearDown(): void
    {
        Channels::$httpGuard = true;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function site(): Site
    {
        $site = $this->makePairedSite();
        $site->name = 'Klant BV';
        $site->save();

        return $site;
    }

    /** @return array<string, mixed> */
    private function event(string $type, int $minutesAgo = 1, array $data = []): array
    {
        return [
            'seq' => ++$this->seq, 'time' => now()->subMinutes($minutesAgo)->getTimestamp(), 'type' => $type, 'object' => 'klant@example.nl',
            'result' => $type === 'smtp.failed' ? 'failed' : 'success', 'source' => 'unknown', 'user_login' => '',
            'data' => $data + ['subject' => 'Bestelling #1001', 'error' => 'SMTP Error: Could not authenticate.', 'diagnose_code' => 'auth', 'initiator' => 'WooCommerce', 'log_id' => 12, 'suppressed' => 2],
        ];
    }

    public function test_failed_mail_is_forwarded_with_the_contract_payload(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        ServerDash::save(self::URL, true);
        $site = $this->site();

        (new EventIngestor())->ingest($site, [$this->event('smtp.failed', 3)]);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) use ($site) {
            $this->assertSame(self::URL, $r->url());
            $this->assertSame('POST', $r->method());
            $this->assertSame('application/json', $r->header('Content-Type')[0]);
            $this->assertSame('NB-OC-Monitor/1.0', $r->header('User-Agent')[0]);
            $this->assertSame([
                'event' => 'smtp.failed',
                'source' => 'oc-panel',
                'site_title' => 'Klant BV',
                'site_url' => 'https://klant.example.nl',
                'message' => 'E-mail van Klant BV niet verstuurd: inloggen lukt niet.',
                'text' => 'E-mail van Klant BV niet verstuurd: inloggen lukt niet.',
                'mail' => [
                    'to' => ['klant@example.nl'],
                    'subject' => 'Bestelling #1001',
                    'error' => 'SMTP Error: Could not authenticate.',
                    'backup_error' => '',
                    'diagnose_code' => 'auth',
                    'diagnose_title' => 'inloggen lukt niet',
                    'initiator' => 'WooCommerce',
                    'log_id' => 12,
                    'time' => '2026-11-04T11:57:00+00:00',
                ],
                'suppressed' => 2,
                'panel_url' => route('sites.show', $site),
                'maillog_url' => 'https://klant.example.nl/wp-admin/admin.php?page=nb-ocmonitor&tab=maillog&log=12',
            ], $r->data());

            return true;
        });
        $this->assertNotNull(ServerDash::status()['last_sent_at']);
        $this->assertSame(1, SiteEvent::query()->count());
    }

    public function test_backup_used_and_recovered_are_forwarded(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        ServerDash::save(self::URL, true);
        $site = $this->site();

        (new EventIngestor())->ingest($site, [
            $this->event('smtp.backup_used', 5, ['backup_error' => '', 'diagnose_code' => 'timeout', 'log_id' => null]),
            $this->event('ocm.login', 4),
            $this->event('smtp.recovered', 2, ['diagnose_code' => '', 'error' => '', 'log_id' => 13]),
        ]);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
        $this->assertCount(2, $sent);

        $this->assertSame('smtp.backup_used', $sent[0]['event']);
        $this->assertSame('E-mail van Klant BV via de backupverbinding verstuurd: de primaire SMTP-verbinding faalde (geen verbinding (time-out)).', $sent[0]['message']);
        $this->assertSame('geen verbinding (time-out)', $sent[0]['mail']['diagnose_title']);
        $this->assertNull($sent[0]['mail']['log_id']);
        $this->assertSame('https://klant.example.nl/wp-admin/admin.php?page=nb-ocmonitor&tab=maillog', $sent[0]['maillog_url']);
        $this->assertSame(['event', 'source', 'site_title', 'site_url', 'message', 'text', 'mail', 'suppressed', 'panel_url', 'maillog_url'], array_keys($sent[0]));
        $this->assertSame(['to', 'subject', 'error', 'backup_error', 'diagnose_code', 'diagnose_title', 'initiator', 'log_id', 'time'], array_keys($sent[0]['mail']));

        $this->assertSame('smtp.recovered', $sent[1]['event']);
        $this->assertSame('E-mail van Klant BV wordt weer verstuurd.', $sent[1]['message']);
        $this->assertSame('', $sent[1]['mail']['diagnose_title']);
        $this->assertSame(13, $sent[1]['mail']['log_id']);
        $this->assertSame(3, SiteEvent::query()->count());
    }

    public function test_nothing_is_forwarded_when_disabled_or_empty(): void
    {
        Http::fake();
        $site = $this->site();
        (new EventIngestor())->ingest($site, [$this->event('smtp.failed')]);

        ServerDash::save(self::URL, false);
        (new EventIngestor())->ingest($site, [$this->event('smtp.failed')]);

        ServerDash::save(self::URL, true);
        ServerDash::save('', true); // URL leeggemaakt
        $this->assertFalse(ServerDash::config()['enabled']);
        (new EventIngestor())->ingest($site, [$this->event('smtp.failed')]);

        Http::assertNothingSent();
        $this->assertSame(3, SiteEvent::query()->count());
    }

    public function test_old_events_and_duplicates_are_not_forwarded(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        ServerDash::save(self::URL, true);
        $site = $this->site();

        $fresh = $this->event('smtp.failed', 10);
        (new EventIngestor())->ingest($site, [$this->event('smtp.failed', 121), $fresh]);
        Http::assertSentCount(1);

        // Opnieuw opgehaald (pull na een push): al bekend, niet nog eens doorsturen.
        $result = (new EventIngestor())->ingest($site, [$fresh], 0);
        $this->assertSame(0, $result['inserted']);
        Http::assertSentCount(1);
        $this->assertSame(2, SiteEvent::query()->count());
        $this->assertSame(2, (int) $site->fresh()->last_event_seq);
    }

    public function test_failure_is_swallowed_and_recorded(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 1) {
                return Http::response('kapot', 500);
            }
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });
        ServerDash::save(self::URL, true);
        $site = $this->site();

        $result = (new EventIngestor())->ingest($site, [$this->event('smtp.failed'), $this->event('smtp.failed')]);

        $this->assertSame(2, $result['inserted']);
        $this->assertSame(2, SiteEvent::query()->count());
        Http::assertSentCount(1); // na de eerste fout de rest van de batch overslaan
        $status = ServerDash::status();
        $this->assertSame('HTTP 500', $status['last_error']);
        $this->assertNotNull($status['last_error_at']);

        (new EventIngestor())->ingest($site, [$this->event('smtp.failed')]);
        $this->assertSame('Connection timed out', ServerDash::status()['last_error']);
        $this->assertSame(3, SiteEvent::query()->count());
    }

    public function test_internal_hosts_are_refused_when_sending(): void
    {
        Channels::$httpGuard = true;
        Http::fake();
        ServerDash::save('https://127.0.0.1/hooks/mail/tok', true);

        (new EventIngestor())->ingest($this->site(), [$this->event('smtp.failed')]);

        Http::assertNothingSent();
        $this->assertStringContainsString('intern netwerk', ServerDash::status()['last_error']);
    }

    public function test_admin_configures_url_with_validation_and_masking(): void
    {
        Channels::$httpGuard = true;
        $this->actingAs($this->makeUser());

        $this->put('/instellingen/meldingen/serverdash', ['url' => 'http://8.8.8.8/hooks/mail/tok', 'enabled' => 1])->assertSessionHasErrors('serverdash');
        $this->put('/instellingen/meldingen/serverdash', ['url' => 'https://10.0.0.5/hooks/mail/tok', 'enabled' => 1])->assertSessionHasErrors('serverdash');
        $this->put('/instellingen/meldingen/serverdash', ['url' => 'https://127.0.0.1/hooks/mail/tok', 'enabled' => 1])->assertSessionHasErrors('serverdash');
        $this->put('/instellingen/meldingen/serverdash', ['url' => 'https://8.8.8.8/iets/anders', 'enabled' => 1])->assertSessionHasErrors('serverdash');
        $this->assertNull(ServerDash::configuredUrl());

        $url = 'https://8.8.8.8/hooks/mail/tok_secret_9f3a';
        $this->put('/instellingen/meldingen/serverdash', ['url' => $url, 'enabled' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($url, ServerDash::activeUrl());
        $this->assertStringNotContainsString('tok_secret', json_encode(Setting::get(ServerDash::SETTING)));
        $this->assertSame('8.8.8.8…9f3a', ServerDash::masked());

        $this->get('/instellingen/meldingen')->assertOk()->assertSee('ServerDash')->assertSee('8.8.8.8…9f3a')
            ->assertDontSee('tok_secret')->assertSee('Beheer → E-mailmeldingen')->assertSee('Testmelding versturen');

        // Ongewijzigd (ingekorte waarde) opslaan houdt de URL; vinkje uit zet doorsturen uit.
        $this->put('/instellingen/meldingen/serverdash', ['url' => '8.8.8.8…9f3a', 'enabled' => 0])->assertSessionHasNoErrors();
        $this->assertSame($url, ServerDash::configuredUrl());
        $this->assertFalse(ServerDash::enabled());

        // Leegmaken = verwijderen en uit.
        $this->put('/instellingen/meldingen/serverdash', ['url' => '', 'enabled' => 1])->assertSessionHasNoErrors();
        $this->assertNull(ServerDash::configuredUrl());
        $this->assertFalse(ServerDash::enabled());

        $this->assertSame(3, ActivityLog::query()->where('action', 'serverdash_updated')->count());
    }

    public function test_test_button_sends_sample_and_reports_result(): void
    {
        Http::fake(['dash.example.org/*' => Http::sequence()->push('ok')->push('nee', 503)]);
        $this->actingAs($this->makeUser());

        $this->post('/instellingen/meldingen/serverdash/test')->assertSessionHasErrors('serverdash');
        Http::assertNothingSent();

        ServerDash::save(self::URL, true);
        $this->post('/instellingen/meldingen/serverdash/test')->assertSessionHasNoErrors()->assertSessionHas('status');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::URL && $r['event'] === 'smtp.test' && $r['source'] === 'oc-panel'
            && $r['mail']['diagnose_title'] === 'inloggen lukt niet' && is_string($r['message']) && $r['message'] === $r['text']);

        $this->post('/instellingen/meldingen/serverdash/test')->assertSessionHasErrors(['serverdash' => 'Testmelding mislukt: HTTP 503']);
        $this->assertSame(2, ActivityLog::query()->where('action', 'serverdash_test')->count());

        $this->get('/instellingen/meldingen')->assertOk()->assertSee('Laatst verstuurd')->assertSee('Laatste fout')->assertSee('HTTP 503');
    }

    public function test_viewer_cannot_configure_serverdash(): void
    {
        $this->actingAs($this->makeUser('viewer'));
        $this->put('/instellingen/meldingen/serverdash', ['url' => 'https://8.8.8.8/hooks/mail/x', 'enabled' => 1])->assertForbidden();
        $this->post('/instellingen/meldingen/serverdash/test')->assertForbidden();
        $this->assertNull(ServerDash::configuredUrl());
    }
}
