<?php

namespace Tests\Feature;

use App\Ocm\Activity;
use App\Ocm\EventIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pages_render_for_admin(): void
    {
        $admin = $this->makeUser();
        $site = $this->makePairedSite();
        $site->inventory = [
            'core' => ['version' => '6.8', 'update' => ['version' => '6.9']],
            'plugins' => [['file' => 'akismet/akismet.php', 'slug' => 'akismet', 'name' => 'Akismet', 'version' => '5.3', 'active' => true, 'auto_update' => false, 'update' => ['version' => '5.4']]],
            'themes' => [['stylesheet' => 'twentytwentyfive', 'name' => 'Twenty Twenty-Five', 'version' => '1.0', 'active' => true, 'update' => null]],
        ];
        $site->save();
        (new EventIngestor())->ingest($site, [['seq' => 1, 'time' => time(), 'type' => 'plugin.update', 'object' => 'akismet/akismet.php', 'from' => '5.3', 'to' => '5.4', 'source' => 'unknown', 'result' => 'failed']]);
        $this->pendingSite('nieuw.example.nl');
        Activity::log('test', ['a' => 1], $site, $admin);

        $this->actingAs($admin);
        foreach (['/', '/sites/nieuw', '/sites/site_test01', '/sites/site_test02', '/activiteit', '/activiteit?tab=audit', '/gebruikers', '/account'] as $url) {
            $this->get($url)->assertOk()->assertSee('OC Monitor', false);
        }
        $this->get('/sites/site_test01')->assertSee('Akismet')->assertSee('Extern / onbekend')->assertSee('WordPress 6.9 beschikbaar');
        $this->get('/sites/site_test02')->assertSee('nbocm1.', false);
    }

    public function test_dark_theme_is_default(): void
    {
        $this->assertStringContainsString("|| 'dark'", file_get_contents(public_path('js/theme.js')));
    }
}
