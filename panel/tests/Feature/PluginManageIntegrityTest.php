<?php

namespace Tests\Feature;

use App\Models\PluginDirectoryEntry;
use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\PluginDirectory;
use App\Ocm\Signer\ResultProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PluginManageIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $version = '0.9.0', string $host = 'klant.example.nl', string $id = 'site_test01'): Site
    {
        $site = new Site(['site_id' => $id, 'name' => ucfirst(explode('.', $host)[0]), 'url' => 'https://' . $host, 'host' => $host]);
        $site->scopes = Site::DEFAULT_SCOPES;
        $site->public_key = $this->siteKeys->publicKeyB64();
        $site->key_id = $this->siteKeys->keyId() . $id;
        $site->paired_at = now();
        $site->status = 'online';
        $site->plugin_version = $version;
        $site->last_seen_at = now();
        $site->inventory = [
            'plugins' => [
                ['file' => 'akismet/akismet.php', 'slug' => 'akismet', 'name' => 'Akismet', 'version' => '5.3', 'active' => true],
                ['file' => 'hello-dolly/hello.php', 'slug' => 'hello-dolly', 'name' => 'Hello Dolly', 'version' => '1.7', 'active' => false],
                ['file' => 'nb-ocmonitor-client/nb-ocmonitor-client.php', 'slug' => 'nb-ocmonitor-client', 'name' => 'NB OC Monitor Client', 'version' => $version, 'active' => true],
            ],
            'themes' => [
                ['stylesheet' => 'astra-child', 'active' => true, 'parent' => 'astra'],
                ['stylesheet' => 'astra', 'active' => false],
                ['stylesheet' => 'twentytwentyfive', 'active' => false],
                ['stylesheet' => 'oceanwp', 'active' => false],
            ],
        ];
        $site->wp_users = ['users' => [['id' => 7, 'login' => 'jan', 'email' => 'jan@bureau.nl', 'roles' => ['administrator'], 'name' => 'Jan', 'registered' => null, 'last_login' => null, 'locked' => false, 'sessions' => 0, 'two_factor' => 'totp']]];
        $site->save();

        return $site;
    }

    private function texts(Site $site): \Illuminate\Support\Collection
    {
        return collect($site->fresh()->issues())->map(fn ($i) => $i['level'] . ': ' . $i['text']);
    }

    public function test_integrity_result_is_stored_and_becomes_issues(): void
    {
        $site = $this->site();
        $job = SiteJob::query()->create(['job_id' => 'job_int', 'site_id' => $site->id, 'command' => 'security.integrity', 'args' => [], 'status' => 'running', 'available_at' => now()]);
        app(ResultProcessor::class)->process($job, $site, [
            'checked_at' => time(), 'wp_version' => '6.9',
            'core' => ['modified' => ['wp-includes/load.php'], 'missing' => [], 'unknown' => ['wp-includes/x.php'], 'checked' => 2000, 'counts' => ['modified' => 1, 'missing' => 0, 'unknown' => 1]],
            'root_unknown' => ['wp-xmlrpc2.php'], 'uploads_php' => ['2024/05/shell.php'], 'uploads_php_count' => 1,
            'plugins' => ['akismet' => ['status' => 'modified', 'modified' => ['akismet.php'], 'unknown' => [], 'counts' => ['modified' => 1, 'unknown' => 0, 'missing' => 0]], 'elementor-pro' => ['status' => 'unverifiable']],
        ]);

        $this->assertNotNull($site->fresh()->integrity_at);
        $texts = $this->texts($site);
        $this->assertTrue($texts->contains('crit: WordPress-bestanden gewijzigd of toegevoegd (2): vergelijk met wordpress.org'));
        $this->assertTrue($texts->contains('crit: Onbekende PHP-bestanden in de hoofdmap: wp-xmlrpc2.php'));
        $this->assertTrue($texts->contains(fn ($t) => str_starts_with($t, 'crit: PHP-bestanden in uploads (1)')));
        $this->assertTrue($texts->contains('warn: Plugin akismet wijkt af van wordpress.org (1 bestand)'));

        $this->actingAs($this->makeUser())->get('/sites/' . $site->site_id)->assertOk()
            ->assertSee('id="integriteit"', false)->assertSee('2024/05/shell.php')->assertSee('1 niet te controleren (premium)');
    }

    public function test_cron_maintenance_inactive_themes_and_autoload_issues(): void
    {
        $site = $this->site();
        $inv = $site->inventory;
        $inv['cron'] = ['disabled' => true, 'events' => 30, 'overdue' => 4, 'oldest_overdue_seconds' => 3 * 86400];
        $inv['maintenance_mode'] = ['enabled' => true, 'until' => now()->addHours(2)->getTimestamp()];
        $inv['cleanup'] = ['autoload_bytes' => 3 * 1048576];
        $site->inventory = $inv;
        $site->save();

        $texts = $this->texts($site);
        $this->assertTrue($texts->contains('warn: WP-Cron loopt achter: 4 taak/taken te laat, de oudste 3 dagen (DISABLE_WP_CRON staat aan: controleer de servercron)'));
        $this->assertTrue($texts->contains(fn ($t) => str_starts_with($t, 'warn: Onderhoudsmodus staat aan tot ')));
        // astra = hoofdthema van het child-thema, twentytwentyfive = reserve: alleen oceanwp telt.
        $this->assertTrue($texts->contains(fn ($t) => str_starts_with($t, 'info: 1 inactief thema')));
        $this->assertTrue($texts->contains('warn: Autoload-opties zijn 3,0 MB: dat wordt bij elke pagina geladen'));
    }

    public function test_plugin_directory_marks_closed_and_abandoned_plugins(): void
    {
        $site = $this->site();
        Http::fake([
            'api.wordpress.org/*akismet*' => Http::response(['slug' => 'akismet', 'last_updated' => '2021-03-01 2:15pm GMT']),
            'api.wordpress.org/*hello-dolly*' => Http::response(['error' => 'closed', 'name' => 'Hello Dolly', 'slug' => 'hello-dolly', 'closed_date' => '2025-01-10', 'reason_text' => 'Security Issue'], 404),
        ]);

        $this->assertSame(['checked' => 2, 'closed' => 1], PluginDirectory::refresh());
        $this->assertTrue(PluginDirectoryEntry::query()->find('hello-dolly')->closed);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'nb-ocmonitor-client'));

        $texts = $this->texts($site);
        $this->assertTrue($texts->contains('warn: Plugin Hello Dolly is gesloten op wordpress.org (Security Issue): zoek een vervanger'));
        $this->assertTrue($texts->contains('info: Plugin Akismet is sinds 03-2021 niet bijgewerkt (mogelijk verlaten)'));

        // Binnen een week niet opnieuw opvragen.
        $this->assertSame(['checked' => 0, 'closed' => 0], PluginDirectory::refresh());
        $this->assertNull(PluginDirectory::parse('x', 500, []));
        $this->assertFalse(PluginDirectory::parse('x', 404, ['error' => 'Plugin not found.'])['listed']);
    }

    public function test_bulk_install_skips_old_plugin_versions_and_installed_sites(): void
    {
        $new = $this->site();
        $old = $this->site('0.8.0', 'oud.example.nl', 'site_test02');
        $this->actingAs($this->makeUser('beheerder'));

        $this->get('/bulk-acties')->assertOk()->assertSee('Hello Dolly · op 2 site(s), 0 actief');
        $this->post('/bulk-acties', ['action' => 'install', 'slug' => 'wordfence', 'activate' => 1, 'sites' => [$new->id, $old->id]])
            ->assertRedirect('/bulk-acties')->assertSessionHasErrors('bulk');
        $job = SiteJob::query()->where('command', 'plugin.install')->sole();
        $this->assertSame($new->id, $job->site_id);
        $this->assertSame(['slug' => 'wordfence', 'activate' => true], $job->args);

        $this->post('/bulk-acties', ['action' => 'install', 'slug' => 'https://evil.example/x.zip', 'sites' => [$new->id]])->assertSessionHasErrors('slug');
        $this->post('/bulk-acties', ['action' => 'install', 'slug' => 'akismet', 'sites' => [$new->id]]);
        $this->assertSame(1, SiteJob::query()->where('command', 'plugin.install')->count(), 'al geïnstalleerd');
    }

    public function test_bulk_deactivate_delete_and_self_protection(): void
    {
        $site = $this->site();
        $this->actingAs($this->makeUser('beheerder'));

        $this->post('/bulk-acties', ['action' => 'deactivate', 'plugin' => 'nb-ocmonitor-client/nb-ocmonitor-client.php', 'sites' => [$site->id]])->assertSessionHasErrors('plugin');
        $this->post('/bulk-acties', ['action' => 'delete', 'plugin' => 'akismet/akismet.php', 'sites' => [$site->id]])->assertSessionHasErrors('bulk');
        $this->post('/bulk-acties', ['action' => 'delete', 'plugin' => 'hello-dolly/hello.php', 'sites' => [$site->id]]);
        $this->post('/bulk-acties', ['action' => 'deactivate', 'plugin' => 'akismet/akismet.php', 'sites' => [$site->id]]);

        $this->assertSame(['plugins' => ['hello-dolly/hello.php']], SiteJob::query()->where('command', 'plugin.delete')->sole()->args);
        $this->assertSame(['plugins' => ['akismet/akismet.php']], SiteJob::query()->where('command', 'plugin.deactivate')->sole()->args);
    }

    public function test_bulk_maintenance_and_user_lock_everywhere(): void
    {
        $a = $this->site();
        $b = $this->site('0.9.0', 'tweede.example.nl', 'site_test02');
        $b->wp_users = ['users' => [['id' => 3, 'login' => 'JanB', 'email' => 'Jan@Bureau.nl', 'roles' => ['editor'], 'name' => 'Jan B', 'registered' => null, 'last_login' => null, 'locked' => false, 'sessions' => 0, 'two_factor' => null]]];
        $b->save();
        $this->actingAs($this->makeUser('beheerder'));

        $this->post('/bulk-acties', ['action' => 'maintenance_on', 'message' => 'Even geduld', 'hours' => 2, 'sites' => [$a->id]]);
        $job = SiteJob::query()->where('command', 'site.maintenance')->sole();
        $this->assertTrue($job->args['enabled']);
        $this->assertSame('Even geduld', $job->args['message']);
        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), $job->args['until'], 5);

        // Geen sites gekozen = alle sites waar de gebruiker bestaat; e-mail zonder hoofdlettergevoeligheid.
        $this->post('/bulk-acties', ['action' => 'user_lock', 'login' => 'jan@bureau.nl'])->assertRedirect('/bulk-acties');
        $locks = SiteJob::query()->where('command', 'users.lock')->orderBy('site_id')->get();
        $this->assertSame([['user_id' => 7], ['user_id' => 3]], $locks->pluck('args')->all());
    }

    public function test_viewer_cannot_run_bulk_actions_and_integrity_needs_090(): void
    {
        $site = $this->site('0.8.0');
        $this->actingAs($this->makeUser('viewer'));
        $this->get('/bulk-acties')->assertOk()->assertSee('Alleen beheerders');
        $this->post('/bulk-acties', ['action' => 'maintenance_off', 'sites' => [$site->id]])->assertForbidden();
    }

    public function test_integrity_command_and_signer_allowlist(): void
    {
        $old = $this->site('0.8.0');
        $this->actingAs($this->makeUser('beheerder'));
        $this->post('/sites/' . $old->site_id . '/opdracht', ['command' => 'security.integrity'])->assertSessionHasErrors('command');
        $old->forceFill(['plugin_version' => '0.9.0'])->save();
        $this->post('/sites/' . $old->site_id . '/opdracht', ['command' => 'security.integrity'])->assertRedirect();
        $this->assertTrue(SiteJob::query()->where('command', 'security.integrity')->exists());

        foreach (['plugin.install', 'plugin.activate', 'plugin.deactivate', 'plugin.delete', 'site.maintenance', 'security.integrity'] as $command) {
            $this->assertContains($command, config('ocm.signer.allowed_commands'));
        }
        $this->artisan('ocm:queue-checks --integrity')->assertSuccessful();
    }
}
