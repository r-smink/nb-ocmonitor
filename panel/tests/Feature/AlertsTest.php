<?php

namespace Tests\Feature;

use App\Mail\AlertDigest;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\Site;
use App\Models\Tag;
use App\Ocm\Alerts\AlertFilter;
use App\Ocm\Alerts\Alerts;
use App\Ocm\Alerts\Channels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 12:00:00');
        config(['ocm.alerts.delay_minutes' => 10, 'ocm.alerts.repeat_hours' => 0]);
        Channels::$httpGuard = false;
    }

    protected function tearDown(): void
    {
        Channels::$httpGuard = true;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function healthySite(): Site
    {
        $site = $this->makePairedSite();
        $site->last_seen_at = now();
        $site->uptime_state = 'up';
        $site->save();

        return $site;
    }

    private function tick(int $minutesLater = 0): array
    {
        Carbon::setTestNow(now()->addMinutes($minutesLater));
        Alerts::track(Site::query()->get());

        return Alerts::deliver();
    }

    private function goDown(Site $site): void
    {
        $site->forceFill(['uptime_state' => 'down', 'down_since' => now(), 'last_seen_at' => now()])->save();
    }

    public function test_existing_problems_at_the_first_run_are_not_notified(): void
    {
        Mail::fake();
        $this->makeUser();
        $site = $this->healthySite();
        $this->goDown($site);

        $this->tick();
        $this->tick(30);

        $this->assertSame(1, Alert::query()->count());
        $this->assertFalse(Alert::query()->first()->notify);
        Mail::assertNothingSent();
    }

    public function test_new_critical_issue_is_mailed_once_after_the_delay_and_again_when_resolved(): void
    {
        Mail::fake();
        $admin = $this->makeUser();
        $site = $this->healthySite();
        $this->tick(); // nulmeting

        $this->goDown($site);
        $this->tick(5);
        Mail::assertNothingSent(); // nog binnen de wachttijd

        $this->tick(10);
        Mail::assertSent(AlertDigest::class, fn (AlertDigest $m) => $m->hasTo($admin->email)
            && $m->items[0]['kind'] === 'open' && str_contains($m->items[0]['text'], 'Website offline'));

        $this->tick(5);
        Mail::assertSentCount(1); // niet opnieuw

        $site->forceFill(['uptime_state' => 'up', 'last_seen_at' => now()])->save();
        $this->tick(5);
        Mail::assertSentCount(2);
        Mail::assertSent(AlertDigest::class, fn (AlertDigest $m) => $m->items[0]['kind'] === 'resolve');
        $this->assertNotNull(Alert::query()->first()->resolved_at);
    }

    public function test_short_outage_within_the_delay_is_never_notified(): void
    {
        Mail::fake();
        $this->makeUser();
        $site = $this->healthySite();
        $this->tick();

        $this->goDown($site);
        $this->tick(5);
        $site->forceFill(['uptime_state' => 'up', 'last_seen_at' => now()])->save();
        $this->tick(5);
        $this->tick(30);

        Mail::assertNothingSent();
    }

    public function test_viewer_gets_nothing_by_default_and_warn_level_needs_opt_in(): void
    {
        Mail::fake();
        $viewer = $this->makeUser('viewer');
        $site = $this->healthySite();
        $this->tick();

        // WP_DEBUG aan = aandacht (warn) in de categorie beveiliging
        $site->inventory = ['environment' => ['wp_debug' => true]];
        $site->save();
        $this->tick(15);
        Mail::assertNothingSent();

        $viewer->alert_prefs = AlertFilter::fromInput(['enabled' => 1, 'min_level' => 'warn', 'resolved' => 1]);
        $viewer->save();
        $this->tick(15);
        Mail::assertSent(AlertDigest::class, fn (AlertDigest $m) => $m->hasTo($viewer->email) && str_contains($m->items[0]['text'], 'WP_DEBUG'));
    }

    public function test_tag_filter_and_escalation(): void
    {
        Mail::fake();
        $admin = $this->makeUser();
        $site = $this->healthySite();
        $tag = Tag::query()->create(['name' => 'webshop', 'color' => 'blue']);
        $admin->alert_prefs = AlertFilter::fromInput(['enabled' => 1, 'min_level' => 'warn', 'tags' => [$tag->id], 'resolved' => 1]);
        $admin->save();
        $this->tick();

        $site->inventory = ['environment' => ['wp_debug' => true]];
        $site->save();
        $this->tick(15);
        Mail::assertNothingSent(); // site heeft de tag niet

        $site->tags()->attach($tag);
        $this->tick(15);
        Mail::assertSentCount(1);
    }

    public function test_quiet_hours_hold_non_critical_items(): void
    {
        $filter = AlertFilter::from(['enabled' => true, 'quiet_from' => '22:00', 'quiet_to' => '07:00', 'quiet_crit' => true]);
        $night = Carbon::parse('2026-11-04 23:30', 'Europe/Amsterdam');
        $this->assertFalse($filter->allowsNow('warn', $night));
        $this->assertTrue($filter->allowsNow('crit', $night));
        $this->assertTrue($filter->allowsNow('warn', Carbon::parse('2026-11-04 08:00', 'Europe/Amsterdam')));
        $strict = AlertFilter::from(['enabled' => true, 'quiet_from' => '22:00', 'quiet_to' => '07:00', 'quiet_crit' => false]);
        $this->assertFalse($strict->allowsNow('crit', $night));
    }

    public function test_slack_and_signed_webhook_channels(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response('ok')]);
        $site = $this->healthySite();
        Channels::save('slack', 'https://hooks.slack.com/services/T/B/x', false, ['min_level' => 'crit']);
        Channels::save('webhook', 'https://hooks.example.org/ocm', false, ['min_level' => 'crit']);
        $this->tick();

        $this->goDown($site);
        $this->tick();
        $this->tick(15);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://hooks.slack.com/services/T/B/x' && str_contains($r['text'], 'Website offline'));
        $secret = Channels::webhookSecret();
        Http::assertSent(function (HttpRequest $r) use ($secret) {
            if ($r->url() !== 'https://hooks.example.org/ocm') {
                return false;
            }
            $expected = 'sha256=' . hash_hmac('sha256', $r->header('X-OCM-Timestamp')[0] . '.' . $r->body(), $secret);

            return $r->header('X-OCM-Signature')[0] === $expected && $r['items'][0]['site']['id'] === 'site_test01';
        });
        $this->assertSame(2, AlertDelivery::query()->whereNotNull('sent_at')->count());
    }

    public function test_failed_channel_is_retried_a_few_times_then_given_up(): void
    {
        Http::fake(['*' => Http::response('nee', 500)]);
        $site = $this->healthySite();
        Channels::save('slack', 'https://hooks.slack.com/services/T/B/x', false, ['min_level' => 'crit']);
        $this->tick();
        $this->goDown($site);
        foreach (range(1, 6) as $i) {
            $this->tick(15);
        }

        $this->assertSame(3, AlertDelivery::query()->whereNull('sent_at')->count());
        $this->assertSame('HTTP 500', AlertDelivery::query()->first()->error);
    }

    public function test_channel_urls_are_validated(): void
    {
        $this->assertNotNull(Channels::urlProblem('http://hooks.slack.com/x', 'slack'));
        $this->assertNotNull(Channels::urlProblem('https://example.org/x', 'slack'));
        $this->assertNotNull(Channels::urlProblem('https://127.0.0.1/x', 'webhook'));
        $this->assertNotNull(Channels::urlProblem('https://10.0.0.5/x', 'webhook'));
        $this->assertNotNull(Channels::urlProblem('https://user:pw@8.8.8.8/x', 'webhook'));
        $this->assertNull(Channels::urlProblem('https://8.8.8.8/hook', 'webhook'));
    }

    public function test_user_edits_own_preferences_and_admin_manages_channels(): void
    {
        $admin = $this->makeUser();
        $this->actingAs($admin);

        $this->get('/account')->assertOk()->assertSee('id="meldingen"', false)->assertSee('Stille uren');
        $this->put('/account/meldingen', ['enabled' => 1, 'min_level' => 'warn', 'categories' => ['beveiliging'], 'quiet_from' => '22:00', 'quiet_to' => '07:00', 'quiet_crit' => 1])
            ->assertRedirect();
        $prefs = $admin->fresh()->alert_prefs;
        $this->assertSame('warn', $prefs['min_level']);
        $this->assertSame(['beveiliging'], $prefs['categories']);
        $this->assertSame('22:00', $prefs['quiet_from']);

        $this->get('/instellingen/meldingen')->assertOk()->assertSee('Microsoft Teams')->assertSee('kritiek en aandacht · beveiliging · stil 22:00-07:00');
        $this->put('/instellingen/meldingen/slack', ['url' => 'https://example.org/x', 'min_level' => 'crit'])->assertSessionHasErrors('url');
        $this->get('/gebruikers')->assertOk()->assertSee('Meldingen');
        $this->get('/gebruikers/' . $admin->id . '/meldingen')->assertOk();
    }

    public function test_viewer_can_set_own_alerts_but_not_others_or_channels(): void
    {
        $admin = $this->makeUser();
        $viewer = $this->makeUser('viewer');
        $this->actingAs($viewer);
        $this->get('/instellingen/meldingen')->assertForbidden();
        $this->put('/gebruikers/' . $admin->id . '/meldingen', ['min_level' => 'crit'])->assertForbidden();
        $this->put('/instellingen/meldingen/slack', ['url' => 'https://hooks.slack.com/services/x', 'min_level' => 'crit'])->assertForbidden();
        $this->put('/account/meldingen', ['enabled' => 1, 'min_level' => 'crit'])->assertRedirect();
        $this->assertTrue($viewer->fresh()->alert_prefs['enabled']);
    }

    public function test_issue_keys_ignore_changing_numbers(): void
    {
        $this->assertSame(\App\Ocm\SiteIssues::key('beveiliging', '6 beheerders zonder 2FA (x)'), \App\Ocm\SiteIssues::key('beveiliging', '7 beheerders zonder 2FA'));
        $this->assertNotSame(\App\Ocm\SiteIssues::key('beveiliging', 'WP_DEBUG staat aan'), \App\Ocm\SiteIssues::key('beveiliging', 'Site draait niet op HTTPS'));
    }
}
