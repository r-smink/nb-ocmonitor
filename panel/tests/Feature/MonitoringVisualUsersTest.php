<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Site;
use App\Models\SiteJob;
use App\Models\VisualCheck;
use App\Ocm\Monitoring\Monitor;
use App\Ocm\Signer\ResultProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringVisualUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Monitor::$sslProbe = null;
        parent::tearDown();
    }

    private function jpegDataUrl(array $rgb, int $w = 400, int $h = 600): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
        ob_start();
        imagejpeg($img, null, 80);

        return 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
    }

    private function siteWithPluginUpdate(string $pluginVersion = '0.4.0'): Site
    {
        $site = $this->makePairedSite();
        $site->plugin_version = $pluginVersion;
        $site->last_seen_at = now();
        $site->inventory = ['core' => ['version' => '6.9'], 'plugins' => [['file' => 'a/a.php', 'slug' => 'a', 'name' => 'A', 'version' => '1', 'update' => ['version' => '2']]], 'themes' => []];
        $site->save();

        return $site;
    }

    public function test_uptime_ssl_and_domain_monitoring(): void
    {
        Monitor::$sslProbe = fn (string $host) => ['expires_at' => now()->addDays(10)->timestamp, 'issuer' => "Let's Encrypt", 'error' => null];
        Http::fake([
            'klant.example.nl/*' => Http::sequence()->push('ok', 200)->push('fout', 503)->push('fout', 503)->push('ok', 200),
            'rdap.org/*' => Http::response(['events' => [['eventAction' => 'registration', 'eventDate' => '2015-01-01T00:00:00Z'], ['eventAction' => 'expiration', 'eventDate' => now()->addDays(20)->toIso8601String()]]]),
        ]);
        $site = $this->makePairedSite();
        $site->forceFill(['last_seen_at' => now()])->save();

        $this->artisan('ocm:monitor')->assertSuccessful();
        $this->assertSame('up', $site->fresh()->uptime_state);
        $this->artisan('ocm:monitor');
        $this->assertSame('up', $site->fresh()->uptime_state, 'één fout is nog geen storing');
        $this->artisan('ocm:monitor');
        $this->assertSame('down', $site->fresh()->uptime_state);
        $this->assertTrue(ActivityLog::query()->where('action', 'site_down')->exists());

        $texts = collect($site->fresh()->issues())->pluck('text');
        $this->assertTrue($texts->contains(fn ($t) => str_starts_with($t, 'Website offline sinds')));
        $this->assertTrue($texts->contains(fn ($t) => str_starts_with($t, 'SSL-certificaat verloopt over')));
        $this->assertTrue($texts->contains(fn ($t) => str_starts_with($t, 'Domein verloopt over')));

        $this->artisan('ocm:monitor');
        $this->assertSame('up', $site->fresh()->uptime_state);
        $this->assertNull($site->fresh()->down_since);
        Http::assertSentCount(5); // 4x homepage, 1x RDAP (SSL en domein maar één keer)

        $stats = Monitor::stats($site, CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addMinute());
        $this->assertSame(50.0, $stats['percent']);
        $this->assertSame(1, $stats['incidents']);

        $this->actingAs($this->makeUser())->get('/sites/' . $site->site_id)->assertOk()->assertSee('50,00%')->assertSee("Let&#039;s Encrypt", false);
        $this->assertSame('example.co.uk', Monitor::registrableDomain('www.shop.example.co.uk'));
        $this->assertSame('klant.nl', Monitor::registrableDomain('www.klant.nl'));
    }

    public function test_self_update_offers_only_the_signed_release(): void
    {
        $dist = storage_path('framework/testing/dist');
        @mkdir($dist, 0777, true);
        file_put_contents($dist . '/nb-ocmonitor-client-0.4.1.zip', 'PK-zip');
        file_put_contents($dist . '/latest.json', json_encode(['version' => '0.4.1', 'file' => 'nb-ocmonitor-client-0.4.1.zip', 'sha256' => hash('sha256', 'PK-zip'), 'signature' => str_repeat('A', 86)]));
        config(['ocm.client_plugin_dist' => $dist]);

        $site = $this->siteWithPluginUpdate('0.4.0');
        $this->assertTrue(collect($site->availableUpdates())->contains('type', 'client'));
        $this->actingAs($this->makeUser())->get('/updates?type=client')->assertOk()->assertSee('NB OC Monitor Client');
        $this->get('/sites/' . $site->site_id)->assertSee('Client-plugin 0.4.0 → 0.4.1 beschikbaar');

        $this->post("/sites/{$site->site_id}/updates", ['type' => 'client'])->assertRedirect();
        $job = SiteJob::query()->where('command', 'plugin.self_update')->firstOrFail();
        $this->assertSame(['version' => '0.4.1', 'sha256' => hash('sha256', 'PK-zip'), 'signature' => str_repeat('A', 86)], $job->args);
        $this->post("/sites/{$site->site_id}/updates", ['type' => 'client']);
        $this->assertSame(1, SiteJob::query()->where('command', 'plugin.self_update')->count());

        $this->get('/api/plugin/nb-ocmonitor-client-0.4.1.zip')->assertOk();
        $this->get('/api/plugin/nb-ocmonitor-client-0.4.0.zip')->assertNotFound();

        // Ouder dan 0.4.0: kan zichzelf niet bijwerken, dus geen aanbod.
        $site->forceFill(['plugin_version' => '0.3.0'])->save();
        $this->assertFalse(collect($site->fresh()->availableUpdates())->contains('type', 'client'));
    }

    public function test_visual_check_holds_updates_and_compares_screenshots(): void
    {
        config(['ocm.pagespeed.api_key' => 'test-key', 'ocm.visual.enabled' => true]);
        Http::fake(['www.googleapis.com/*' => Http::sequence()
            ->push(['lighthouseResult' => ['fullPageScreenshot' => ['screenshot' => ['data' => $this->jpegDataUrl([255, 255, 255])]]]])
            ->push(['lighthouseResult' => ['fullPageScreenshot' => ['screenshot' => ['data' => $this->jpegDataUrl([20, 40, 200])]]]])]);
        $site = $this->siteWithPluginUpdate();

        $this->actingAs($this->makeUser())->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'ids' => ['a/a.php']]);
        $job = SiteJob::query()->where('command', 'update.plugin')->firstOrFail();
        $this->assertTrue($job->available_at->isFuture(), 'update wacht op de screenshot vooraf');
        $check = VisualCheck::query()->firstOrFail();
        $this->assertSame('before', $check->status);
        $this->assertSame([$job->id], $check->job_ids);

        $this->artisan('ocm:visual')->assertSuccessful();
        $this->assertSame('updating', $check->fresh()->status);
        $this->assertFalse($job->fresh()->available_at->isFuture());

        $this->artisan('ocm:visual');
        $this->assertSame('updating', $check->fresh()->status, 'update is nog niet klaar');

        $job->update(['status' => 'done', 'finished_at' => now()->subMinutes(5)]);
        $this->artisan('ocm:visual');
        $check->refresh();
        $this->assertSame('done', $check->status);
        $this->assertGreaterThan(90, $check->diff_percent);
        $this->assertTrue($check->changed());

        $this->assertTrue(collect($site->fresh()->issues())->pluck('text')->contains(fn ($t) => str_starts_with($t, 'Homepage zichtbaar veranderd')));
        $this->get('/sites/' . $site->site_id)->assertOk()->assertSee('Visuele controle na updates')->assertSee('data:image/jpeg;base64,', false);
        $this->post("/sites/{$site->site_id}/visueel/{$check->id}/gezien")->assertRedirect();
        $this->assertNotNull($check->fresh()->reviewed_at);
        $this->assertFalse(collect($site->fresh()->issues())->pluck('text')->contains(fn ($t) => str_starts_with($t, 'Homepage zichtbaar veranderd')));
    }

    public function test_visual_check_never_blocks_updates_when_screenshot_fails(): void
    {
        config(['ocm.pagespeed.api_key' => 'test-key', 'ocm.visual.enabled' => true]);
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'Quota']], 429)]);
        $site = $this->siteWithPluginUpdate();
        $this->actingAs($this->makeUser())->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'ids' => ['a/a.php']]);
        $this->artisan('ocm:visual');
        $this->assertSame('skipped', VisualCheck::query()->value('status'));
        $this->assertFalse(SiteJob::query()->where('command', 'update.plugin')->first()->available_at->isFuture());
    }

    public function test_wp_users_overview_and_actions(): void
    {
        $site = $this->siteWithPluginUpdate();
        $job = SiteJob::query()->create(['job_id' => 'job_users', 'site_id' => $site->id, 'command' => 'users.list', 'args' => [], 'status' => 'done', 'available_at' => now()]);
        app(ResultProcessor::class)->process($job, $site, [
            'users' => [
                ['id' => 1, 'login' => 'beheer', 'email' => 'beheer@klant.example', 'name' => 'Beheer', 'roles' => ['administrator'], 'last_login' => time() - 3600, 'locked' => false, 'two_factor' => 'wordfence', 'sessions' => 1],
                ['id' => 7, 'login' => 'stagiair', 'email' => 's@klant.example', 'name' => '<script>x</script>', 'roles' => ['administrator', 'BAD ROLE!'], 'locked' => false, 'two_factor' => null, 'sessions' => 3],
                ['login' => 'zonder-id'],
            ],
            'total' => 12,
            'two_factor_mode' => 'off',
        ]);
        $site->refresh();
        $this->assertCount(2, $site->wp_users['users']);
        $this->assertSame(['administrator'], $site->wp_users['users'][1]['roles']);
        $this->assertContains('1 beheerder zonder tweestapsverificatie', collect($site->issues())->pluck('text')->all());

        $this->actingAs($this->makeUser());
        $this->get('/wp-gebruikers')->assertOk()->assertSee('stagiair')->assertSeeInOrder(['Zonder 2FA', '<b>1</b>'], false)->assertDontSee('<script>x</script>', false);
        $this->get('/wp-gebruikers?twofa=zonder')->assertOk()->assertSee('stagiair')->assertDontSee('beheer@klant.example');
        $this->get('/sites/' . $site->site_id)->assertOk()->assertSee('WordPress-gebruikers')->assertSee('Wordfence');

        $this->post("/sites/{$site->site_id}/wp-gebruikers/7", ['action' => 'lock'])->assertRedirect();
        $this->assertSame(['user_id' => 7], SiteJob::query()->where('command', 'users.lock')->firstOrFail()->args);
        $this->post("/sites/{$site->site_id}/wp-gebruikers/7", ['action' => 'demote', 'role' => 'administrator'])->assertSessionHasErrors('role');
        $this->post("/sites/{$site->site_id}/wp-gebruikers/7", ['action' => 'demote', 'role' => 'subscriber']);
        $this->assertSame(['user_id' => 7, 'role' => 'subscriber'], SiteJob::query()->where('command', 'users.demote')->firstOrFail()->args);
        $this->post("/sites/{$site->site_id}/wp-gebruikers/99", ['action' => 'lock'])->assertNotFound();

        $this->post('/wp-gebruikers/2fa', ['mode' => 'admins'])->assertRedirect();
        $this->assertSame(['mode' => 'admins'], SiteJob::query()->where('command', 'security.two_factor')->firstOrFail()->args);

        // Na een actie wordt de lijst opnieuw opgehaald.
        $lock = SiteJob::query()->where('command', 'users.lock')->first();
        app(ResultProcessor::class)->process($lock, $site, ['user_id' => 7, 'locked' => true]);
        $this->assertTrue(SiteJob::query()->where('command', 'users.list')->where('status', 'pending')->exists());

        $site->forceFill(['plugin_version' => '0.3.0'])->save();
        $this->post("/sites/{$site->site_id}/wp-gebruikers/7", ['action' => 'logout'])->assertSessionHasErrors('users');
    }
}
