<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\JobQueue;
use App\Ocm\Updates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use NextBuzz\OcMonitor\Protocol\Canonical;
use NextBuzz\OcMonitor\Protocol\InMemoryNonceStore;
use NextBuzz\OcMonitor\Protocol\Signer;
use NextBuzz\OcMonitor\Protocol\StaticKeyResolver;
use NextBuzz\OcMonitor\Protocol\Verifier;
use Tests\TestCase;

class UpdatesTest extends TestCase
{
    use RefreshDatabase;

    private function siteWithUpdates(string $host = 'klant.example.nl', string $siteId = 'site_test01', int $plugins = 2): Site
    {
        $site = $this->makePairedSite($host);
        $site->site_id = $siteId;
        $site->plugin_version = '0.2.0';
        $list = [];
        for ($i = 1; $i <= $plugins; $i++) {
            $list[] = ['file' => "plugin-{$i}/plugin-{$i}.php", 'slug' => "plugin-{$i}", 'name' => "Plugin {$i}", 'version' => '1.0', 'active' => true, 'update' => ['version' => '1.1']];
        }
        $list[] = ['file' => 'actueel/actueel.php', 'slug' => 'actueel', 'name' => 'Actueel', 'version' => '2.0', 'active' => true, 'update' => null];
        $site->inventory = [
            'core' => ['version' => '6.8', 'update' => ['version' => '6.9']],
            'plugins' => $list,
            'themes' => [['stylesheet' => 'astra', 'name' => 'Astra', 'version' => '4.0', 'active' => true, 'update' => ['version' => '4.1']]],
            'translations_pending' => 3,
        ];
        $site->save();

        return $site;
    }

    public function test_pages_render_with_update_actions(): void
    {
        $this->actingAs($this->makeUser());
        $site = $this->siteWithUpdates();

        $this->get('/')->assertOk()->assertSee('Aandachtspunten')->assertSee('Openstaande updates');
        $this->get('/updates')->assertOk()->assertSee('Plugin 1')->assertSee('Bijwerken op 1 site')->assertSee('site_test01|plugin|plugin-1/plugin-1.php', false);
        $this->get('/updates?type=theme')->assertOk()->assertSee('Astra')->assertDontSee('plugin-1/plugin-1.php', false);
        $this->get('/updates?component=plugin:plugin-2')->assertOk()->assertSee('plugin-2/plugin-2.php', false)->assertDontSee('plugin-1/plugin-1.php', false);
        $this->get('/pagespeed')->assertOk();
        $this->get('/sites')->assertOk()->assertSee('Klant');
        $this->get('/sites/' . $site->site_id)->assertOk()
            ->assertSee('Alle plugins bijwerken (2)')
            ->assertSee('WordPress bijwerken naar 6.9')
            ->assertSee('Vertalingen bijwerken (3)')
            ->assertSee('Nu meten');
    }

    public function test_bulk_update_only_queues_what_the_inventory_offers(): void
    {
        $user = $this->makeUser();
        $a = $this->siteWithUpdates('a.example.nl', 'site_a');
        $b = $this->siteWithUpdates('b.example.nl', 'site_b');

        $this->actingAs($user)->post('/updates', ['items' => [
            'site_a|plugin|plugin-1/plugin-1.php',
            'site_a|plugin|plugin-2/plugin-2.php',
            'site_a|plugin|actueel/actueel.php',     // geen update beschikbaar
            'site_a|plugin|../../wp-config.php',     // bestaat niet in de inventaris
            'site_b|theme|astra',
            'site_b|core|wordpress',
            'site_x|plugin|plugin-1/plugin-1.php',  // onbekende site
            'site_b|user|admin',                     // onbekend type
        ]])->assertRedirect()->assertSessionHas('status');

        $jobs = SiteJob::query()->orderBy('id')->get();
        $this->assertSame(['update.plugin', 'update.theme', 'update.core'], $jobs->pluck('command')->all());
        $this->assertSame(['plugins' => ['plugin-1/plugin-1.php', 'plugin-2/plugin-2.php']], $jobs[0]->args);
        $this->assertSame($a->id, $jobs[0]->site_id);
        $this->assertSame(['themes' => ['astra']], $jobs[1]->args);
        $this->assertSame($b->id, $jobs[2]->site_id);
        $this->assertSame($user->id, $jobs[0]->requested_by);
        $this->assertTrue(ActivityLog::query()->where('action', 'updates_requested')->exists());

        // Nogmaals hetzelfde: alles staat al in de wachtrij.
        $this->post('/updates', ['items' => ['site_a|plugin|plugin-1/plugin-1.php']]);
        $this->assertSame(3, SiteJob::query()->count());
    }

    public function test_all_plugins_on_a_site_are_chunked(): void
    {
        $site = $this->siteWithUpdates(plugins: 7);
        $this->actingAs($this->makeUser())->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'all' => 1])->assertRedirect();

        $jobs = SiteJob::query()->where('command', 'update.plugin')->orderBy('id')->get();
        $this->assertCount(2, $jobs);
        $this->assertCount(5, $jobs[0]->args['plugins']);
        $this->assertCount(2, $jobs[1]->args['plugins']);
        $this->assertSame(7, collect(Updates::queuedIds($site)['plugin'])->unique()->count());
    }

    public function test_viewer_cannot_start_updates(): void
    {
        $this->siteWithUpdates();
        $this->actingAs($this->makeUser('viewer'))->post('/updates', ['items' => ['site_test01|plugin|plugin-1/plugin-1.php']])->assertForbidden();
        $this->assertSame(0, SiteJob::query()->count());
        $this->get('/updates')->assertOk()->assertDontSee('Geselecteerde bijwerken');
    }

    public function test_updates_are_blocked_without_scope_or_with_old_plugin(): void
    {
        $site = $this->siteWithUpdates();
        $site->scopes = ['read', 'events'];
        $site->save();
        $this->actingAs($this->makeUser())->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'ids' => ['plugin-1/plugin-1.php']])
            ->assertSessionHasErrors('updates');

        $site->scopes = Site::DEFAULT_SCOPES;
        $site->plugin_version = '0.1.0';
        $site->save();
        $this->assertStringContainsString('te oud', (string) $site->updatesBlockedReason());
        $this->post('/updates', ['items' => ['site_test01|plugin|plugin-1/plugin-1.php']])->assertSessionHasErrors('updates');
        $this->assertSame(0, SiteJob::query()->count());
    }

    /** Signer → nagebootste site → verwerking: inventaris opnieuw ophalen en problemen in het audit log. */
    public function test_signer_runs_update_and_refreshes_inventory(): void
    {
        $site = $this->siteWithUpdates();
        $seen = [];
        Http::fake(function (HttpRequest $request) use ($site, &$seen) {
            $verified = (new Verifier(new StaticKeyResolver([$this->panelKeys->publicKey()]), new InMemoryNonceStore()))
                ->verifyRequest(Canonical::CTX_COMMAND, 'POST', $site->host, '/nb-ocm/v1/cmd', $site->site_id, $request->headers(), $request->body(), time());
            $command = json_decode($request->body(), true);
            $seen[] = [$command['command'], $command['args']];
            $result = match ($command['command']) {
                'update.plugin' => ['items' => [
                    ['id' => 'plugin-1/plugin-1.php', 'from' => '1.0', 'to' => '1.1', 'status' => 'updated', 'message' => null, 'health' => ['ok' => true, 'status' => 200, 'detail' => 'HTTP 200']],
                    ['id' => 'plugin-2/plugin-2.php', 'from' => '1.0', 'to' => '1.0', 'status' => 'rolled_back', 'message' => 'Site gaf HTTP 500', 'health' => null],
                ]],
                'site.inventory' => ['core' => ['version' => '6.8'], 'plugins' => [], 'themes' => []],
                default => [],
            };
            $body = json_encode(['ok' => true, 'job_id' => $command['job_id'], 'result' => $result], JSON_UNESCAPED_SLASHES);

            return Http::response($body, 200, (new Signer($this->siteKeys))->signResponse($site->site_id, $verified->nonce, 200, $body, time()));
        });

        Updates::queue($site, 'plugin', ['plugin-1/plugin-1.php', 'plugin-2/plugin-2.php'], null);
        $this->artisan('ocm:signer --once')->assertSuccessful();

        $this->assertSame(['update.plugin', ['plugins' => ['plugin-1/plugin-1.php', 'plugin-2/plugin-2.php']]], $seen[0]);
        $this->assertSame(['site.inventory', ['refresh' => true]], $seen[1]);
        $job = SiteJob::query()->where('command', 'update.plugin')->firstOrFail();
        $this->assertSame('done', $job->status);
        $this->assertSame('rolled_back', $job->result['items'][1]['status']);
        $this->assertTrue(ActivityLog::query()->where('action', 'update_problem')->exists());
        $this->assertSame(0, $site->fresh()->updates_count);

        $this->actingAs($this->makeUser())->get('/updates')->assertSee('teruggedraaid');
    }

    public function test_heartbeat_syncs_scopes_from_the_site(): void
    {
        $site = $this->makePairedSite();
        Http::fake(function (HttpRequest $request) use ($site) {
            $verified = (new Verifier(new StaticKeyResolver([$this->panelKeys->publicKey()]), new InMemoryNonceStore()))
                ->verifyRequest(Canonical::CTX_COMMAND, 'POST', $site->host, '/nb-ocm/v1/cmd', $site->site_id, $request->headers(), $request->body(), time());
            $command = json_decode($request->body(), true);
            $body = json_encode(['ok' => true, 'job_id' => $command['job_id'], 'result' => [
                'wp_version' => '6.9', 'php_version' => '8.3', 'plugin_version' => '0.2.0', 'last_event_seq' => 0, 'scopes' => ['read', 'events', 'BAD SCOPE'],
            ]], JSON_UNESCAPED_SLASHES);

            return Http::response($body, 200, (new Signer($this->siteKeys))->signResponse($site->site_id, $verified->nonce, 200, $body, time()));
        });
        JobQueue::enqueue($site, 'site.info');
        $this->artisan('ocm:signer --once')->assertSuccessful();

        $site->refresh();
        $this->assertSame(['read', 'events'], $site->scopes);
        $this->assertNotNull($site->updatesBlockedReason());
    }
}
