<?php

namespace Tests\Feature;

use App\Models\Vulnerability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private function siteWithData()
    {
        $site = $this->makePairedSite();
        $site->plugin_version = '0.9.0';
        $site->last_seen_at = now();
        $site->response_ms = 640;
        $site->ssl_expires_at = now()->addDays(62);
        $site->inventory = [
            'core' => ['version' => '6.8', 'update' => ['version' => '6.9']],
            'plugins' => [
                ['file' => 'elementor/elementor.php', 'slug' => 'elementor', 'name' => 'Elementor', 'version' => '3.20.0', 'active' => true, 'update' => ['version' => '3.21.0']],
                ['file' => 'akismet/akismet.php', 'slug' => 'akismet', 'name' => 'Akismet', 'version' => '5.3', 'active' => true, 'update' => ['version' => '5.4']],
            ],
            'themes' => [['stylesheet' => 'astra', 'name' => 'Astra', 'version' => '4', 'active' => true]],
        ];
        $site->save();
        Vulnerability::query()->create([
            'uuid' => 'vuln-1', 'type' => 'plugin', 'slug' => 'elementor', 'title' => 'Stored XSS in Elementor', 'cve' => 'CVE-2026-1234',
            'cvss_score' => 7.5, 'affected' => [['from_version' => '*', 'to_version' => '3.20.0', 'to_inclusive' => true]], 'patched_versions' => ['3.20.1'],
        ]);

        return $site;
    }

    public function test_rail_has_one_icon_per_section_with_anchors_and_badges(): void
    {
        $site = $this->siteWithData();
        $page = $this->actingAs($this->makeUser())->get('/sites/' . $site->site_id)->assertOk();

        $page->assertSee('data-scrollspy', false)
            ->assertSeeInOrder(['href="#sd-status"', 'href="#sd-updates"', 'href="#sd-backups"', 'href="#sd-beveiliging"', 'href="#sd-pagespeed"', 'href="#sd-gebruikers"', 'href="#sd-activiteit"'], false)
            ->assertSeeInOrder(['id="sd-status"', 'id="sd-updates"', 'id="sd-backups"', 'id="sd-beveiliging"', 'id="sd-pagespeed"', 'id="sd-gebruikers"', 'id="sd-activiteit"'], false)
            // 3 updates (WordPress, Elementor, Akismet) oranje, 1 kwetsbaarheid rood
            ->assertSee('<span class="sd-rail-count warn" aria-hidden="true">3</span>', false)
            ->assertSee('<span class="sd-rail-count crit" aria-hidden="true">1</span>', false)
            ->assertSee('class="sd-rail-label"', false)
            ->assertSee('class="app-shell"', false)
            ->assertSee('class="main-wide"', false);
    }

    public function test_sections_show_metrics_updates_and_vulnerabilities_with_actions(): void
    {
        $site = $this->siteWithData();
        $page = $this->actingAs($this->makeUser())->get('/sites/' . $site->site_id)->assertOk();

        $page->assertSeeInOrder(['Beschikbaarheid', 'SSL-certificaat', '62 d', 'Laatste back-up', 'Responstijd', '640 ms'])
            ->assertSee('Openstaande updates')
            ->assertSee('Alles bijwerken (2)')
            ->assertSee('name="items[]" value="site_test01|plugin|elementor/elementor.php"', false)
            // Elementor lost het lek op: beveiligingsbadge bij de update en een Bijwerken-knop bij de kwetsbaarheid.
            ->assertSeeInOrder(['Elementor', 'beveiliging', '3.20.0', '3.21.0'], false)
            ->assertSeeInOrder(['id="sd-beveiliging"', '7.5', 'Stored XSS in Elementor', 'CVE-2026-1234', 'opgelost in', '3.20.1', 'Bijwerken'], false)
            ->assertSee('Site openen');
    }

    public function test_queued_update_shows_in_wachtrij_and_viewer_gets_no_buttons(): void
    {
        $site = $this->siteWithData();
        $this->actingAs($this->makeUser());
        $this->post('/sites/' . $site->site_id . '/updates', ['type' => 'plugin', 'ids' => ['akismet/akismet.php']])->assertRedirect();
        $this->get('/sites/' . $site->site_id)->assertSeeInOrder(['Akismet', '5.4', 'In wachtrij']);
    }

    public function test_viewer_sees_sections_without_action_buttons(): void
    {
        $site = $this->siteWithData();
        $this->actingAs($this->makeUser('viewer'))->get('/sites/' . $site->site_id)->assertOk()
            ->assertSee('id="sd-updates"', false)
            ->assertDontSee('Alles bijwerken')
            ->assertDontSee('>Bijwerken</button>', false)
            ->assertDontSee('Site verwijderen');
    }

    public function test_unpaired_site_only_has_status_and_activity(): void
    {
        $site = $this->pendingSite();
        $this->actingAs($this->makeUser())->get('/sites/' . $site->site_id)->assertOk()
            ->assertSee('href="#sd-status"', false)->assertSee('href="#sd-activiteit"', false)
            ->assertDontSee('href="#sd-updates"', false)->assertDontSee('href="#sd-beveiliging"', false)
            ->assertSee('Koppelen');
    }
}
