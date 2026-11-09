<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PluginChangelog;
use App\Models\PluginDirectoryEntry;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\SiteMetric;
use App\Models\UpdateInsight;
use App\Models\Vulnerability;
use App\Ocm\Ai\Claude;
use App\Ocm\Changelogs;
use App\Ocm\Reports\ReportData;
use App\Ocm\Reports\ReportSummary;
use App\Ocm\SiteMetrics;
use App\Ocm\UpdateRisk;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateRiskReportTrendsTest extends TestCase
{
    use RefreshDatabase;

    private const CHANGELOG = '<h4>5.0.1</h4><ul><li>Fix: tabel &amp; knop</li></ul>'
        . '<h4>5.0.0</h4><ul><li>Breaking change: oude shortcodes verwijderd</li><li>Nieuwe editor</li></ul>'
        . '<p><strong>4.9.2</strong></p><ul><li>Security fix</li></ul>'
        . '<h4>4.9.1</h4><p>Kleine fix</p>';

    protected function tearDown(): void
    {
        Claude::fake(null);
        parent::tearDown();
    }

    private function site(): Site
    {
        $site = $this->makePairedSite();
        $site->php_version = '7.4.33';
        $site->wp_version = '6.8.3';
        $site->inventory = [
            'core' => ['version' => '6.8.3', 'update' => ['version' => '6.9']],
            'plugins' => [
                ['file' => 'forms/forms.php', 'slug' => 'forms', 'name' => 'Forms', 'version' => '4.9.1', 'active' => true, 'update' => ['version' => '5.0.1'], 'updated_at' => now()->subMonths(15)->getTimestamp()],
                ['file' => 'akismet/akismet.php', 'slug' => 'akismet', 'name' => 'Akismet', 'version' => '5.3', 'active' => true, 'update' => ['version' => '5.3.1'], 'updated_at' => now()->subWeek()->getTimestamp()],
            ],
            'themes' => [['stylesheet' => 'astra', 'name' => 'Astra', 'version' => '4.8', 'active' => true]],
            'cleanup' => ['db_bytes' => 50 * 1024 * 1024],
            'storage' => ['content_bytes' => 300 * 1024 * 1024, 'uploads_bytes' => 200 * 1024 * 1024, 'partial' => false],
        ];
        $site->save();

        return $site;
    }

    public function test_changelog_html_is_split_per_version(): void
    {
        $entries = Changelogs::parse(self::CHANGELOG);

        $this->assertSame(['5.0.1', '5.0.0', '4.9.2', '4.9.1'], array_column($entries, 'version'));
        $this->assertSame("- Fix: tabel & knop", $entries[0]['text']);
        $this->assertStringContainsString('- Breaking change: oude shortcodes verwijderd', $entries[1]['text']);
        $this->assertSame(['5.0.1', '5.0.0', '4.9.2'], array_column(Changelogs::between($entries, '4.9.1', '5.0.1'), 'version'));
        $this->assertSame([], Changelogs::parse('<p>Zie de website van de auteur.</p>'));
    }

    public function test_risk_combines_versions_requirements_changelog_and_staleness(): void
    {
        $site = $this->site();
        PluginDirectoryEntry::query()->create(['slug' => 'forms', 'listed' => true, 'closed' => false, 'last_updated' => now()->subDays(3), 'checked_at' => now(),
            'version' => '5.0.1', 'requires_php' => '8.1', 'requires_wp' => '6.5', 'tested' => '6.7']);
        PluginChangelog::query()->create(['slug' => 'forms', 'version' => '5.0.1', 'entries' => Changelogs::parse(self::CHANGELOG), 'fetched_at' => now()]);

        $risks = UpdateRisk::forSite($site);
        $forms = $risks['plugin:forms/forms.php'];
        $texts = array_column($forms['reasons'], 'text');

        $this->assertSame('high', $forms['level']);
        $this->assertSame('Vereist PHP 8.1; de site draait 7.4', $texts[0]);
        $this->assertContains('Changelog 5.0.0 noemt "breaking"', $texts);
        $this->assertContains('Grote versiesprong (4.x → 5.x)', $texts);
        $this->assertContains('Al 15 maanden niet bijgewerkt op deze site', $texts);
        $this->assertContains('Getest tot WordPress 6.7', $texts);

        $this->assertSame('medium', $risks['core:wordpress']['level']);
        $this->assertSame('Nieuwe hoofdversie van WordPress (6.9)', $risks['core:wordpress']['reasons'][0]['text']);
        // Kleine update, niets bekend: laag zonder redenen (en dus geen label).
        $this->assertSame(['level' => 'low', 'reasons' => [], 'summary' => null], $risks['plugin:akismet/akismet.php']);

        // Eisen horen bij de nieuwste versie: staat er op wordpress.org al een nieuwere, dan niet gebruiken.
        PluginDirectoryEntry::query()->where('slug', 'forms')->update(['version' => '5.1.0']);
        $this->assertNotContains('Vereist PHP 8.1; de site draait 7.4', array_column(UpdateRisk::forSite($site)['plugin:forms/forms.php']['reasons'], 'text'));
    }

    public function test_refresh_fetches_changelogs_and_claude_summarizes_each_jump_once(): void
    {
        $this->site();
        Http::fake([
            'api.wordpress.org/*' => Http::response(['slug' => 'forms', 'version' => '5.0.1', 'requires_php' => '8.1', 'requires' => '6.5', 'tested' => '6.7', 'sections' => ['changelog' => self::CHANGELOG]]),
        ]);
        PluginDirectoryEntry::query()->create(['slug' => 'akismet', 'listed' => false, 'closed' => false, 'checked_at' => now()]);
        PluginDirectoryEntry::query()->create(['slug' => 'forms', 'listed' => true, 'closed' => false, 'checked_at' => now()]);

        // Zonder Claude: alleen ophalen. Akismet staat niet op wordpress.org en wordt overgeslagen.
        $this->assertSame(['fetched' => 1, 'summarized' => 0], Changelogs::refresh());
        Http::assertSentCount(1);
        $this->assertSame('8.1', PluginDirectoryEntry::query()->find('forms')->requires_php);
        $this->assertCount(4, PluginChangelog::query()->find('forms')->entries);

        $prompts = [];
        Claude::fake(function (string $system, string $prompt) use (&$prompts) {
            $prompts[] = $prompt;

            return ['summary' => 'Nieuwe editor; oude shortcodes verdwijnen. Bevat een beveiligingsfix.', 'risk' => 'high', 'reason' => 'Shortcodes verwijderd'];
        });
        config(['ocm.ai.changelog' => true]);

        $this->assertSame(['fetched' => 0, 'summarized' => 1], Changelogs::refresh());
        $this->assertCount(1, $prompts);
        $this->assertStringContainsString('Van versie 4.9.1 naar 5.0.1 (3 versies)', $prompts[0]);
        $this->assertStringContainsString('Breaking change: oude shortcodes verwijderd', $prompts[0]);
        $this->assertStringNotContainsString('klant.example.nl', $prompts[0]);
        $this->assertStringNotContainsString('Kleine fix', $prompts[0], 'de geïnstalleerde versie zelf hoort er niet bij');

        // Dezelfde sprong niet opnieuw.
        $this->assertSame(['fetched' => 0, 'summarized' => 0], Changelogs::refresh());
        $this->assertSame(1, UpdateInsight::query()->count());
    }

    public function test_site_and_bulk_pages_show_risk_labels_summary_and_filter(): void
    {
        $site = $this->site();
        UpdateInsight::query()->create(['type' => 'plugin', 'slug' => 'forms', 'from_version' => '4.9.1', 'to_version' => '5.0.1',
            'summary' => 'Nieuwe editor; oude shortcodes verdwijnen.', 'risk' => 'high', 'reason' => 'Shortcodes verwijderd', 'model' => 'claude-opus-5-5']);
        $this->actingAs($this->makeUser());

        $this->get('/sites/' . $site->site_id)->assertOk()
            ->assertSeeInOrder(['Forms', 'hoog risico', 'Nieuwe editor; oude shortcodes verdwijnen.', '4.9.1', '5.0.1'], false)
            ->assertSee('Claude: Shortcodes verwijderd')
            ->assertSee('middel risico');

        $this->get('/updates?risk=high')->assertOk()->assertSee('hoog risico')->assertSee('forms/forms.php')->assertDontSee('akismet/akismet.php');
        $this->get('/updates?risk=low')->assertOk()->assertSee('akismet/akismet.php')->assertDontSee('forms/forms.php');
    }

    public function test_metrics_are_recorded_per_day_and_drawn_as_trends(): void
    {
        $site = $this->site();
        $this->travelTo(now()->subDays(10));
        SiteMetrics::record($site);
        $this->travelBack();
        $inventory = $site->inventory;
        $inventory['cleanup']['db_bytes'] = 60 * 1024 * 1024;
        $site->inventory = $inventory;
        $site->save();
        SiteMetrics::record($site);
        SiteMetrics::record($site); // zelfde dag: overschrijven, geen tweede punt

        $this->assertSame(2, SiteMetric::query()->count());
        $this->assertSame(2, SiteMetric::query()->latest('day')->first()->plugins_active);
        $series = SiteMetrics::series($site);
        $this->assertSame([50 * 1024 * 1024, 60 * 1024 * 1024], array_column($series['db'], 1));
        $this->assertSame('1,2 GB', SiteMetrics::bytes(1.2 * 1024 ** 3));

        $this->actingAs($this->makeUser())->get('/sites/' . $site->site_id)->assertOk()
            ->assertSee('href="#sd-trends"', false)
            ->assertSeeInOrder(['Database', '60 MB', '+10 MB sinds'], false)
            ->assertSee('class="line"', false)
            ->assertSee('Bestanden (wp-content)')
            ->assertSee('<summary>Tabel</summary>', false)
            ->assertSee('<title>' . now()->format('d-m-Y') . ': 60 MB</title>', false);
    }

    public function test_report_paragraph_from_figures_and_optionally_from_claude(): void
    {
        $text = ReportSummary::text([
            'period_days' => 91, 'sites' => 2, 'updates_done' => 14, 'updates_failed' => 0, 'updates_pending' => 1, 'vulnerabilities_fixed' => 1,
            'uptime_percent' => 99.98, 'outages' => 0, 'pagespeed_mobile_before' => 62, 'pagespeed_mobile_after' => 70, 'failed_logins_blocked' => 1234,
        ]);
        $this->assertSame('Deze periode zijn er 14 updates uitgevoerd op de 2 websites, waarmee 1 bekende kwetsbaarheid is gedicht. '
            . 'De 2 websites waren 99,98% van de tijd bereikbaar. De PageSpeed-score op mobiel ging van 62 naar 70 (13% beter). '
            . 'Er zijn 1.234 verdachte inlogpogingen tegengehouden. 1 update staat nog klaar voor het volgende onderhoud.', $text);

        // Uit echte gegevens: de update van Forms 4.9.1 → 5.0.1 dicht een lek dat tot en met 4.9.1 speelde.
        $site = $this->site();
        $customer = Customer::query()->create(['name' => 'Bakkerij De Korst', 'email' => 'anna@korst.example', 'visits_per_year' => 4, 'contract_start' => '2026-01-15']);
        $site->customer_id = $customer->id;
        $site->save();
        Vulnerability::query()->create(['uuid' => 'v1', 'type' => 'plugin', 'slug' => 'forms', 'title' => 'XSS', 'cvss_score' => 6.1,
            'affected' => [['from_version' => '*', 'to_version' => '4.9.1', 'to_inclusive' => true]], 'patched_versions' => ['4.9.2']]);
        SiteEvent::query()->create(['site_id' => $site->id, 'seq' => 1, 'occurred_at' => now()->subDays(5), 'type' => 'plugin.update', 'object' => 'forms/forms.php',
            'from_version' => '4.9.1', 'to_version' => '5.0.1', 'result' => 'success', 'source' => 'panel']);
        $from = CarbonImmutable::today()->subMonths(3);
        $data = ReportData::build($customer, $from, CarbonImmutable::today());
        $this->assertSame(1, $data['totals']['vulns_fixed']);
        $this->assertStringStartsWith('Deze periode is er 1 update uitgevoerd op de website, waarmee 1 bekende kwetsbaarheid is gedicht.', $data['narrative']);

        // Met Claude: alleen cijfers erheen, en het antwoord wordt bewaard.
        $calls = [];
        Claude::fake(function (string $system, string $prompt) use (&$calls) {
            $calls[] = $prompt;

            return ['paragraph' => 'Het was een rustige periode voor de website.'];
        });
        config(['ocm.ai.report' => true]);
        $this->assertSame('Het was een rustige periode voor de website.', ReportData::build($customer, $from, CarbonImmutable::today())['narrative']);
        $this->assertSame('Het was een rustige periode voor de website.', ReportData::build($customer, $from, CarbonImmutable::today())['narrative']);
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('"vulnerabilities_fixed": 1', $calls[0]);
        foreach (['Bakkerij', 'korst', 'klant.example.nl', 'Forms'] as $private) {
            $this->assertStringNotContainsStringIgnoringCase($private, $calls[0]);
        }

        // Geen bruikbaar antwoord: terug naar de vaste tekst.
        // Geen bruikbaar antwoord bij nieuwe cijfers (een extra update): terug naar de vaste tekst.
        Claude::fake(fn () => null);
        SiteEvent::query()->create(['site_id' => $site->id, 'seq' => 2, 'occurred_at' => now()->subDays(4), 'type' => 'plugin.update', 'object' => 'akismet/akismet.php',
            'from_version' => '5.2', 'to_version' => '5.3', 'result' => 'success', 'source' => 'panel']);
        $this->assertStringStartsWith('Deze periode zijn er 2 updates uitgevoerd', ReportData::build($customer, $from, CarbonImmutable::today())['narrative']);
    }

    public function test_claude_is_off_without_key_or_setting(): void
    {
        config(['ocm.ai.api_key' => null, 'ocm.ai.report' => true]);
        $this->assertFalse(Claude::enabled('report'));
        config(['ocm.ai.api_key' => 'sk-test', 'ocm.ai.report' => false]);
        $this->assertFalse(Claude::enabled('report'));
        config(['ocm.ai.report' => true]);
        $this->assertTrue(Claude::enabled('report'));
    }
}
