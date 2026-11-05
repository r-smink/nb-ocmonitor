<?php

namespace Tests\Feature;

use App\Models\BrokenLink;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\GoogleApi;
use App\Ocm\LinkChecker;
use App\Ocm\Signer\ResultProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TweaksLinksAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $version = '0.10.0'): Site
    {
        $site = $this->makePairedSite();
        $site->forceFill(['plugin_version' => $version, 'last_seen_at' => now(), 'inventory' => ['core' => ['version' => '6.9'], 'plugins' => [], 'themes' => [], 'tweaks' => ['disable_comments' => false, 'revisions' => -1, 'heartbeat' => 'default']]])->save();

        return $site;
    }

    public function test_tweaks_on_the_site_page_and_in_bulk(): void
    {
        $site = $this->site();
        $this->actingAs($this->makeUser());
        $this->get('/sites/' . $site->site_id)->assertOk()->assertSee('Schakelaars')->assertSee('Reacties uitzetten');

        $this->post("/sites/{$site->site_id}/schakelaars", ['disable_comments' => '1', 'hide_wp_version' => '1', 'revisions' => '5', 'heartbeat' => 'slow', 'evil' => '1'])->assertRedirect();
        $job = SiteJob::query()->where('command', 'tweaks.set')->sole();
        $this->assertTrue($job->args['tweaks']['disable_comments']);
        $this->assertFalse($job->args['tweaks']['disable_emojis']);
        $this->assertSame(5, $job->args['tweaks']['revisions']);
        $this->assertSame('slow', $job->args['tweaks']['heartbeat']);
        $this->assertArrayNotHasKey('evil', $job->args['tweaks']);

        app(ResultProcessor::class)->process($job, $site, ['tweaks' => $job->args['tweaks'] + ['revisions' => 5], 'changed' => ['disable_comments']]);
        $this->assertTrue($site->fresh()->inventory['tweaks']['disable_comments']);

        $this->post('/bulk-acties', ['action' => 'tweak', 'sites' => [$site->id], 'tweak' => 'disable_emojis', 'tweak_value' => '1'])->assertRedirect();
        $this->assertSame(['tweaks' => ['disable_emojis' => true]], SiteJob::query()->where('command', 'tweaks.set')->latest('id')->first()->args);

        $site->forceFill(['plugin_version' => '0.9.0'])->save();
        $this->post("/sites/{$site->site_id}/schakelaars", ['disable_comments' => '1'])->assertSessionHasErrors('tweaks');
    }

    public function test_broken_link_checker(): void
    {
        $page = '<html><body><a href="/goed">ok</a> <a href="/weg">kapot</a> <a href="https://extern.example/stuk">ext</a>'
            . ' <a href="https://linkedin.example/x">li</a> <a href="mailto:info@klant.nl">mail</a> <a href="#top">top</a><img src="../img/foto.jpg"></body></html>';
        Http::fake(function (HttpRequest $r) use ($page) {
            $url = $r->url();
            return match (true) {
                str_ends_with($url, '/sitemap_index.xml') => Http::response('<?xml version="1.0"?><sitemapindex><sitemap><loc>https://klant.example.nl/page-sitemap.xml</loc></sitemap></sitemapindex>'),
                str_ends_with($url, '/page-sitemap.xml') => Http::response('<?xml version="1.0"?><urlset><url><loc>https://klant.example.nl/over/</loc></url><url><loc>https://elders.example/x</loc></url></urlset>'),
                $url === 'https://klant.example.nl/over/' => Http::response($page, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
                str_contains($url, '/weg'), str_contains($url, '/img/foto.jpg') => Http::response('', 404),
                str_contains($url, 'extern.example') => Http::response('', 500),
                str_contains($url, 'linkedin.example') => Http::response('', 999),
                default => Http::response('', 200),
            };
        });
        $site = $this->site();
        $site->forceFill(['links_requested_at' => now()])->save();

        $this->artisan('ocm:links')->assertSuccessful()->expectsOutputToContain("1 pagina's, 5 links, 3 kapot");
        $this->assertEqualsCanonicalizing(['https://klant.example.nl/weg', 'https://klant.example.nl/img/foto.jpg', 'https://extern.example/stuk'], BrokenLink::query()->pluck('url')->all());
        $this->assertNull($site->fresh()->links_requested_at);
        $this->assertContains('3 kapotte links (2 intern)', collect($site->fresh()->issues())->pluck('text')->all());

        $this->actingAs($this->makeUser());
        $this->get('/kapotte-links')->assertOk()->assertSee('https://klant.example.nl/weg')->assertSee('HTTP 404')->assertDontSee('linkedin.example');
        $this->get('/sites/' . $site->site_id)->assertOk()->assertSee('Kapotte links')->assertSee('HTTP 500');

        $link = BrokenLink::query()->where('url', 'https://extern.example/stuk')->sole();
        $this->post("/kapotte-links/{$link->id}/negeren")->assertRedirect();
        $this->get('/kapotte-links')->assertDontSee('extern.example/stuk');

        $this->assertSame('https://klant.example.nl/a/c.png', LinkChecker::absolute('../c.png', 'https://klant.example.nl/a/b/pagina'));
        $this->assertNull(LinkChecker::absolute('tel:0612345678', 'https://klant.example.nl/'));
    }

    private function connectGoogle(): void
    {
        config(['ocm.google.client_id' => 'cid', 'ocm.google.client_secret' => 'geheim']);
        Setting::put(GoogleApi::SETTING, ['refresh_token' => Crypt::encryptString('refresh-1'), 'email' => 'seo@nextbuzz.nl', 'connected_at' => now()->toIso8601String()]);
    }

    public function test_google_analytics_and_search_console(): void
    {
        $this->connectGoogle();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            'analyticsadmin.googleapis.com/*' => Http::response(['accountSummaries' => [['displayName' => 'Klant BV', 'propertySummaries' => [['property' => 'properties/123', 'displayName' => 'klant.example.nl']]]]]),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [['siteUrl' => 'sc-domain:klant.example.nl'], ['siteUrl' => 'https://ander.example/']]]),
            'analyticsdata.googleapis.com/*' => function (HttpRequest $r) {
                $body = $r->data();
                $dim = $body['dimensions'][0]['name'] ?? null;
                return Http::response(match ($dim) {
                    null => ['rows' => [['metricValues' => [['value' => '1234'], ['value' => '1500'], ['value' => '4200'], ['value' => '0.61'], ['value' => '95']]]]],
                    'pagePath' => ['rows' => [['dimensionValues' => [['value' => '/contact/']], 'metricValues' => [['value' => '800']]]]],
                    'date' => ['rows' => [['dimensionValues' => [['value' => '20261101']], 'metricValues' => [['value' => '50']]], ['dimensionValues' => [['value' => '20261102']], 'metricValues' => [['value' => '70']]]]],
                    default => ['rows' => [['dimensionValues' => [['value' => 'Organic Search']], 'metricValues' => [['value' => '900']]]]],
                });
            },
            'www.googleapis.com/webmasters/v3/sites/*' => function (HttpRequest $r) {
                return Http::response(isset($r->data()['dimensions'])
                    ? ['rows' => [['keys' => ['bakkerij amsterdam'], 'clicks' => 40, 'impressions' => 900, 'position' => 3.2]]]
                    : ['rows' => [['clicks' => 120, 'impressions' => 5400, 'ctr' => 0.0222, 'position' => 8.4]]]);
            },
        ]);
        $site = $this->site();
        $this->actingAs($this->makeUser());

        $this->get('/sites/' . $site->site_id)->assertOk()->assertSee('Klant BV › klant.example.nl')->assertSee('value="sc-domain:klant.example.nl" selected', false);
        $this->post("/sites/{$site->site_id}/google", ['ga4_property' => 'properties/999'])->assertSessionHasErrors('ga4_property');
        $this->post("/sites/{$site->site_id}/google", ['ga4_property' => 'properties/123', 'gsc_property' => 'sc-domain:klant.example.nl'])->assertSessionHasNoErrors();

        $this->get('/sites/' . $site->site_id)->assertOk()->assertSee('1.234')->assertSee('bezoekers')->assertSee('/contact/')->assertSee('bakkerij amsterdam')->assertSee('8,4');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'properties/123:runReport') && $r->hasHeader('Authorization', 'Bearer at-1'));

        $report = GoogleApi::report($site->fresh(), CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        $this->assertSame(1234, $report['ga4']['users']);
        $this->assertSame(120, $report['gsc']['clicks']);
        $this->assertSame(2.2, $report['gsc']['ctr']);
    }

    public function test_google_connect_flow(): void
    {
        config(['ocm.google.client_id' => 'cid', 'ocm.google.client_secret' => 'geheim']);
        $this->actingAs($this->makeUser());
        $redirect = $this->post('/instellingen/google/koppelen')->assertRedirect();
        $location = $redirect->headers->get('Location');
        $this->assertStringContainsString('accounts.google.com/o/oauth2/v2/auth', $location);
        $this->assertStringContainsString('analytics.readonly', urldecode($location));
        $this->assertStringContainsString('access_type=offline', $location);
        $this->assertStringContainsString("form-action 'self' https://accounts.google.com", (string) $redirect->headers->get('Content-Security-Policy'));

        $this->get('/instellingen/google/terug?state=fout&code=x')->assertStatus(400)->assertSee('Ongeldig of verlopen');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600]),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response(['email' => 'seo@nextbuzz.nl']),
        ]);
        // Terugkeer zonder sessie (zoals de browser doet bij SameSite=strict): de state is genoeg.
        auth()->logout();
        $this->flushSession();
        $this->get('/instellingen/google/terug?state=' . $q['state'] . '&code=abc')->assertOk()
            ->assertSee('Google gekoppeld als seo@nextbuzz.nl')->assertSee('http-equiv="refresh"', false);
        // De state werkt maar één keer.
        $this->get('/instellingen/google/terug?state=' . $q['state'] . '&code=abc')->assertStatus(400);
        $this->assertTrue(GoogleApi::connected());
        $this->assertSame('seo@nextbuzz.nl', GoogleApi::status()['email']);
        $this->assertStringNotContainsString('"rt"', json_encode(Setting::get(GoogleApi::SETTING)));

    }

    public function test_viewer_cannot_connect_google_or_change_tweaks(): void
    {
        config(['ocm.google.client_id' => 'cid', 'ocm.google.client_secret' => 'geheim']);
        $site = $this->site();
        $this->actingAs($this->makeUser('viewer'));
        $this->post('/instellingen/google/koppelen')->assertForbidden();
        $this->post("/sites/{$site->site_id}/schakelaars", ['disable_comments' => '1'])->assertForbidden();
        $this->assertSame(0, SiteJob::query()->count());
    }
}
