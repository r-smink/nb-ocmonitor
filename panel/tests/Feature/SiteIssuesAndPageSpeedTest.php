<?php

namespace Tests\Feature;

use App\Models\PagespeedResult;
use App\Models\Site;
use App\Ocm\EventIngestor;
use App\Ocm\PageSpeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteIssuesAndPageSpeedTest extends TestCase
{
    use RefreshDatabase;

    private function event(int $seq, string $type, string $object, ?string $from, ?string $to, string $source = 'unknown', array $data = []): array
    {
        return ['seq' => $seq, 'time' => time() - 3600, 'type' => $type, 'object' => $object, 'from' => $from, 'to' => $to,
            'result' => 'success', 'source' => $source, 'user_login' => '', 'data' => $data];
    }

    private function texts(Site $site, string $level): array
    {
        return collect($site->fresh()->issues())->where('level', $level)->pluck('text')->all();
    }

    public function test_security_issues_are_detected_and_ranked(): void
    {
        $site = $this->makePairedSite();
        $site->last_seen_at = now();
        $site->php_version = '8.1.27';
        $site->inventory = [
            'core' => ['version' => '6.9'],
            'plugins' => [['file' => 'oud/oud.php', 'slug' => 'oud', 'name' => 'Oud', 'version' => '1', 'active' => false]],
            'themes' => [],
            'environment' => ['https' => true, 'wp_debug' => true, 'disallow_file_edit' => false, 'users_can_register' => true, 'default_role' => 'administrator', 'blog_public' => true],
        ];
        $site->save();
        (new EventIngestor())->ingest($site, [
            $this->event(1, 'user.created', 'hacker', null, 'administrator', 'rest'),
            $this->event(2, 'user.created', 'collega', null, 'administrator', 'wp-admin'),
            $this->event(3, 'option.changed', 'siteurl', 'https://klant.example.nl', 'https://evil.example'),
            $this->event(4, 'component.changed_externally', 'plugin:x/x.php', '1.0', '1.1'),
            $this->event(5, 'login.failed_summary', '', null, null, 'cron', ['count' => 120]),
            $this->event(6, 'user.created', 'abonnee', null, 'subscriber', 'unknown'),
        ]);

        $crit = $this->texts($site, 'crit');
        $this->assertContains('Iedereen kan zich registreren als administrator', $crit);
        $this->assertTrue(collect($crit)->contains(fn ($t) => str_starts_with($t, 'Nieuwe beheerder hacker')));
        $this->assertTrue(collect($crit)->contains(fn ($t) => str_starts_with($t, 'Site-adres gewijzigd (siteurl')));

        $warn = $this->texts($site, 'warn');
        $this->assertTrue(collect($warn)->contains(fn ($t) => str_starts_with($t, 'Nieuwe beheerder collega')));
        $this->assertContains('WP_DEBUG staat aan', $warn);
        $this->assertContains('PHP 8.1 krijgt geen beveiligingsupdates meer', $warn);
        $this->assertContains('120 mislukte inlogpogingen in 24 uur', $warn);
        $this->assertTrue(collect($warn)->contains(fn ($t) => str_contains($t, 'buiten WordPress om')));

        $this->assertContains('1 inactieve plugin (verwijderen verkleint het aanvalsoppervlak)', $this->texts($site, 'info'));
        $this->assertFalse(collect($site->fresh()->issues())->contains(fn ($i) => str_contains($i['text'], 'abonnee')));

        $this->assertSame('crit', $site->fresh()->level());
        $this->assertSame('crit', $site->fresh()->issues()[0]['level']);

        $this->actingAs($this->makeUser());
        $this->get('/')->assertOk()->assertSee('Nieuwe beheerder hacker')->assertDontSee('inactieve plugin');
        $this->get('/?info=1&cat=beveiliging')->assertOk()->assertSee('inactieve plugin');
        $this->get('/?cat=prestaties')->assertOk()->assertDontSee('Nieuwe beheerder hacker');
    }

    public function test_healthy_site_is_ok(): void
    {
        $site = $this->makePairedSite();
        $site->last_seen_at = now();
        $site->php_version = '8.4.1';
        $site->inventory = ['core' => ['version' => '6.9'], 'plugins' => [], 'themes' => [],
            'environment' => ['https' => true, 'wp_debug' => false, 'disallow_file_edit' => true, 'users_can_register' => false, 'default_role' => 'subscriber', 'blog_public' => true]];
        $site->save();

        $this->assertSame([], $site->fresh()->issues());
        $this->assertSame('ok', $site->fresh()->level());
    }

    private function psiResponse(float $performance, bool $field = true): array
    {
        return [
            'lighthouseResult' => [
                'categories' => [
                    'performance' => ['score' => $performance], 'accessibility' => ['score' => 0.91],
                    'best-practices' => ['score' => 1], 'seo' => ['score' => 0.85],
                ],
                'audits' => [
                    'largest-contentful-paint' => ['numericValue' => 3456.7],
                    'first-contentful-paint' => ['numericValue' => 1200],
                    'total-blocking-time' => ['numericValue' => 310],
                    'server-response-time' => ['numericValue' => 420],
                    'cumulative-layout-shift' => ['numericValue' => 0.1234],
                    'render-blocking-resources' => ['title' => 'Verwijder bronnen die de weergave blokkeren', 'score' => 0.3, 'displayValue' => 'Mogelijke besparing van 1,2 s', 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 1200]],
                    'unused-css-rules' => ['title' => 'Verminder ongebruikte CSS', 'score' => 0.5, 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 50]],
                    'uses-http2' => ['title' => 'HTTP/2', 'score' => 1, 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 900]],
                ],
            ],
            'loadingExperience' => $field ? [
                'overall_category' => 'AVERAGE',
                'metrics' => [
                    'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 2900],
                    'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 240],
                    'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 8],
                ],
            ] : ['id' => 'x'],
        ];
    }

    public function test_pagespeed_measures_both_strategies_and_parses_results(): void
    {
        config(['ocm.pagespeed.api_key' => 'test-key']);
        $site = $this->makePairedSite();
        Http::fake(['www.googleapis.com/*' => Http::response($this->psiResponse(0.42))]);

        $this->artisan('ocm:pagespeed')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'url=https%3A%2F%2Fklant.example.nl%2F')
            && str_contains($r->url(), '&category=performance&category=accessibility&category=best-practices&category=seo')
            && str_contains($r->url(), 'key=test-key'));

        $mobile = PagespeedResult::query()->where('strategy', 'mobile')->firstOrFail();
        $this->assertSame(42, $mobile->performance);
        $this->assertSame(91, $mobile->accessibility);
        $this->assertSame(100, $mobile->best_practices);
        $this->assertSame(3457, $mobile->lcp_ms);
        $this->assertSame(0.123, $mobile->cls);
        $this->assertSame(240, $mobile->field['inp_ms']);
        $this->assertSame(0.08, $mobile->field['cls']);
        $this->assertSame(['render-blocking-resources'], array_column($mobile->opportunities, 'id'));
        $this->assertTrue(PagespeedResult::query()->where('strategy', 'desktop')->exists());

        // Recent gemeten: de volgende run slaat deze site over.
        $this->artisan('ocm:pagespeed')->assertSuccessful();
        Http::assertSentCount(2);

        $this->assertContains('PageSpeed mobiel 42', collect($site->fresh()->issues())->pluck('text')->all());
        $this->actingAs($this->makeUser());
        $this->get('/pagespeed')->assertOk()->assertSee('240 ms');
        $this->get('/sites/' . $site->site_id)->assertOk()->assertSee('Verwijder bronnen die de weergave blokkeren');
    }

    public function test_requested_measurement_and_errors(): void
    {
        $site = $this->makePairedSite();
        $site->pagespeedResults()->create(['strategy' => 'mobile', 'performance' => 90, 'measured_at' => now()->subDays(2)]);
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429)]);

        $this->actingAs($this->makeUser())->post('/sites/' . $site->site_id . '/pagespeed')->assertRedirect();
        $this->assertNotNull($site->fresh()->pagespeed_requested_at);

        $this->artisan('ocm:pagespeed')->assertSuccessful();
        $this->assertNull($site->fresh()->pagespeed_requested_at);
        $this->assertSame('PageSpeed Insights gaf HTTP 429: Quota exceeded', PagespeedResult::query()->whereNotNull('error')->first()->error);

        // Na een fout niet meteen opnieuw.
        $this->artisan('ocm:pagespeed')->assertSuccessful();
        Http::assertSentCount(2);
        $this->get('/sites/' . $site->site_id)->assertSee('Laatste meting mislukt');

        // Een geslaagde meting daarna verbergt de oude fout.
        $site->pagespeedResults()->create(['strategy' => 'mobile', 'performance' => 70, 'measured_at' => now()]);
        $site->pagespeedResults()->create(['strategy' => 'desktop', 'performance' => 71, 'measured_at' => now()]);
        $this->get('/sites/' . $site->site_id)->assertDontSee('Laatste meting mislukt');
    }

    public function test_score_drop_is_reported(): void
    {
        $site = $this->makePairedSite();
        $site->last_seen_at = now();
        $site->save();
        $site->pagespeedResults()->create(['strategy' => 'mobile', 'performance' => 88, 'measured_at' => now()->subDays(8)]);
        $site->pagespeedResults()->create(['strategy' => 'mobile', 'performance' => 71, 'measured_at' => now()->subDay()]);

        $this->assertContains('PageSpeed mobiel gedaald van 88 naar 71', collect($site->fresh()->issues())->pluck('text')->all());
    }

    public function test_url_builder(): void
    {
        config(['ocm.pagespeed.api_key' => null]);
        $url = PageSpeed::url('https://klant.example.nl/', 'desktop');
        $this->assertStringStartsWith(PageSpeed::ENDPOINT . '?url=https%3A%2F%2Fklant.example.nl%2F&strategy=desktop&locale=nl&category=performance', $url);
        $this->assertStringNotContainsString('key=', $url);
    }
}
