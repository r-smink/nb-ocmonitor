<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WerkplekUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_is_grouped_and_shows_update_badge(): void
    {
        $site = $this->makePairedSite();
        $site->inventory = ['plugins' => [['file' => 'akismet/akismet.php', 'slug' => 'akismet', 'name' => 'Akismet', 'version' => '5.3', 'active' => true, 'update' => ['version' => '5.4']]]];
        $site->updates_count = 1;
        $site->save();
        $this->actingAs($this->makeUser());

        $this->get('/')->assertOk()
            ->assertSeeInOrder(['Monitoring', '>Overzicht<', '>Kwetsbaarheden<', 'Onderhoud', '>Updates<', 'Beheer', '>Instellingen<'], false)
            ->assertSee('<span class="nav-count">1</span>', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('1 van 1 sites verbonden');
    }

    public function test_dashboard_row_action_posts_to_updates_and_then_shows_queued(): void
    {
        $site = $this->makePairedSite();
        $site->inventory = ['plugins' => [['file' => 'akismet/akismet.php', 'slug' => 'akismet', 'name' => 'Akismet', 'version' => '5.3', 'active' => true, 'update' => ['version' => '5.4']]]];
        $site->last_seen_at = now();
        $site->save();
        $this->actingAs($this->makeUser());

        $this->get('/')->assertOk()
            ->assertSee('Deze week')
            ->assertSee('value="site_test01|plugin|akismet/akismet.php"', false)
            ->assertSee('Bijwerken');

        $this->post('/updates', ['items' => ['site_test01|plugin|akismet/akismet.php']])->assertRedirect();

        $this->get('/')->assertOk()->assertSee('In wachtrij')
            ->assertDontSee('name="items[]" value="site_test01|plugin|akismet/akismet.php"', false);
    }

    public function test_viewer_gets_links_instead_of_action_forms(): void
    {
        $site = $this->makePairedSite();
        $site->inventory = ['plugins' => [['file' => 'akismet/akismet.php', 'slug' => 'akismet', 'name' => 'Akismet', 'version' => '5.3', 'active' => true, 'update' => ['version' => '5.4']]]];
        $site->save();
        $this->actingAs($this->makeUser('viewer'));

        $this->get('/')->assertOk()->assertSee('Bekijken')->assertDontSee('action="' . route('updates.store') . '"', false);
    }
}
