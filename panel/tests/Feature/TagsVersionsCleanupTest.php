<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteJob;
use App\Models\Tag;
use App\Ocm\Signer\ResultProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagsVersionsCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $host, string $siteId, string $php = '8.3.10', string $plugin = '0.6.0'): Site
    {
        $site = $this->makePairedSite($host);
        $site->forceFill([
            'site_id' => $siteId, 'name' => ucfirst(strtok($host, '.')), 'php_version' => $php, 'wp_version' => '6.8.3', 'plugin_version' => $plugin, 'last_seen_at' => now(),
            'inventory' => [
                'core' => ['version' => '6.8.3', 'update' => ['version' => '6.9']],
                'plugins' => [['file' => 'a/a.php', 'name' => 'A', 'version' => '1', 'active' => false, 'update' => ['version' => '2']]],
                'themes' => [['stylesheet' => 'astra', 'name' => 'Astra', 'version' => '4.8', 'active' => true]],
                'db_server' => '8.0.36',
                'cleanup' => ['counts' => ['revisions' => 120, 'spam' => 3, 'trash' => 0], 'db_bytes' => 52428800, 'autoload_bytes' => 900000, 'media' => ['total' => 400, 'unattached' => 37]],
            ],
        ])->save();

        return $site;
    }

    public function test_tags_on_sites_and_customers_filter_every_overview(): void
    {
        $shop = $this->site('winkel.example.nl', 'site_shop');
        $blog = $this->site('blog.example.nl', 'site_blog');
        $this->actingAs($this->makeUser());

        $this->post('/tags', ['name' => 'webshop', 'color' => 'green'])->assertRedirect();
        $this->post('/tags', ['name' => 'webshop', 'color' => 'green'])->assertSessionHasErrors('name');
        $this->post('/tags', ['name' => 'hosting', 'color' => 'paars'])->assertSessionHasErrors('color');
        $this->post('/tags', ['name' => 'hosting', 'color' => 'purple']);
        $webshop = Tag::query()->where('name', 'webshop')->sole();
        $hosting = Tag::query()->where('name', 'hosting')->sole();

        $this->post("/sites/{$shop->site_id}/tags", ['tags' => [$webshop->id]])->assertRedirect();
        $customer = Customer::query()->create(['name' => 'Blogger', 'email' => 'b@example.nl']);
        $this->put("/klanten/{$customer->id}", ['name' => 'Blogger', 'email' => 'b@example.nl', 'visits_per_year' => 0, 'sites' => [$blog->id], 'tags' => [$hosting->id]])->assertRedirect();
        $this->assertTrue($blog->fresh()->load('customer.tags')->allTags()->contains('id', $hosting->id), 'tag via de klant');

        $this->get('/sites?tag=' . $webshop->id)->assertOk()->assertSee('Winkel')->assertDontSee('blog.example.nl');
        $this->get('/sites?tag=' . $hosting->id)->assertOk()->assertSee('blog.example.nl')->assertDontSee('winkel.example.nl');
        $this->get('/sites?indeling=klant')->assertOk()->assertSee('Blogger')->assertSee('Zonder klant');
        $this->get('/sites?indeling=tag')->assertOk()->assertSee('group-head', false)->assertSee('webshop');
        $this->get('/updates?tag=' . $webshop->id)->assertOk()->assertSee('site_shop|plugin|a/a.php', false)->assertDontSee('site_blog|plugin|a/a.php', false);
        $this->get('/?tag=' . $hosting->id)->assertOk();
        $this->get('/pagespeed?tag=' . $hosting->id)->assertOk();
        $this->get('/wp-gebruikers?tag=' . $hosting->id)->assertOk();
        $this->get('/tags')->assertOk()->assertSee('webshop');

        $this->delete('/tags/' . $webshop->id)->assertRedirect();
        $this->assertSame(0, $shop->fresh()->tags()->count());
    }

    public function test_versions_overview_flags_old_php_and_wordpress(): void
    {
        $this->site('oud.example.nl', 'site_oud', '8.0.30');
        $this->site('nieuw.example.nl', 'site_nieuw', '8.4.1');
        $this->actingAs($this->makeUser())->get('/versies')->assertOk()
            ->assertSee('PHP 8.0: 1')->assertSee('PHP 8.4: 1')
            ->assertSee('<span class="ver crit"', false)
            ->assertSee('(6.9)')
            ->assertSee('50,0 MB');
    }

    public function test_cleanup_is_validated_queued_and_processed(): void
    {
        $site = $this->site('winkel.example.nl', 'site_shop');
        $this->actingAs($this->makeUser());
        $this->get('/sites/' . $site->site_id)->assertOk()->assertSee('Database opschonen')->assertSee('(120)', false)->assertSee('37 niet aan een bericht gekoppeld');

        $this->post("/sites/{$site->site_id}/opschonen", ['items' => ['revisions', 'alles']])->assertSessionHasErrors('items.1');
        $this->post("/sites/{$site->site_id}/opschonen", ['items' => ['revisions', 'spam']])->assertRedirect();
        $job = SiteJob::query()->where('command', 'db.cleanup')->sole();
        $this->assertSame(['items' => ['revisions', 'spam']], $job->args);
        $this->post("/sites/{$site->site_id}/opschonen", ['items' => ['spam']]);
        $this->assertSame(1, SiteJob::query()->where('command', 'db.cleanup')->count(), 'niet twee keer tegelijk');

        app(ResultProcessor::class)->process($job, $site, ['deleted' => ['revisions' => 120, 'spam' => 3], 'more' => false, 'stats' => ['counts' => ['revisions' => 0, 'spam' => 0], 'db_bytes' => 40000000]]);
        $this->assertSame(0, $site->fresh()->inventory['cleanup']['counts']['revisions']);

        $old = $this->site('oud.example.nl', 'site_oud', '8.3.1', '0.5.0');
        $this->post("/sites/{$old->site_id}/opschonen", ['items' => ['spam']])->assertSessionHasErrors('cleanup');
    }
}
