<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Site;
use App\Models\SiteJob;
use App\Models\VisualCheck;
use App\Ocm\Signer\ResultProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ocm.backups.enabled' => true,
            'filesystems.disks.backups.key' => 'spaces-key',
            'filesystems.disks.backups.bucket' => 'nb-ocm-backups',
        ]);
        $disk = Storage::fake('backups');
        $disk->buildTemporaryUploadUrlsUsing(fn ($path) => ['url' => 'https://ams3.digitaloceanspaces.com/nb-ocm-backups/' . $path . '?X-Amz-Signature=up', 'headers' => ['Host' => 'ams3.digitaloceanspaces.com']]);
        $disk->buildTemporaryUrlsUsing(fn ($path) => 'https://ams3.digitaloceanspaces.com/nb-ocm-backups/' . $path . '?X-Amz-Signature=down');
    }

    private function site(string $version = '0.5.0', bool $shop = true): Site
    {
        $site = $this->makePairedSite();
        $site->plugin_version = $version;
        $site->last_seen_at = now();
        $plugins = [['file' => 'a/a.php', 'slug' => 'a', 'name' => 'A', 'version' => '1', 'update' => ['version' => '2']]];
        if ($shop) {
            $plugins[] = ['file' => 'woocommerce/woocommerce.php', 'slug' => 'woocommerce', 'name' => 'WooCommerce', 'version' => '9.0', 'active' => true, 'update' => null];
        }
        $site->inventory = ['core' => ['version' => '6.9'], 'plugins' => $plugins, 'themes' => [['stylesheet' => 'astra', 'name' => 'Astra', 'version' => '4', 'update' => ['version' => '5']]]];
        $site->save();

        return $site;
    }

    private function finish(SiteJob $job, Site $site, array $result): void
    {
        $job->update(['status' => 'done', 'finished_at' => now()]);
        app(ResultProcessor::class)->process($job, $site, $result);
    }

    public function test_updates_wait_for_the_backup_and_restore_needs_confirmation_on_shops(): void
    {
        $site = $this->site();
        $this->actingAs($this->makeUser());
        $this->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'ids' => ['a/a.php']]);
        $this->post("/sites/{$site->site_id}/updates", ['type' => 'theme', 'ids' => ['astra']]);

        $backup = Backup::query()->sole();
        $this->assertSame('pending', $backup->status);
        $this->assertTrue($backup->woocommerce);
        $this->assertSame(['plugins' => ['a/a.php'], 'themes' => ['astra'], 'core' => false], $backup->components);
        $create = SiteJob::query()->where('command', 'backup.create')->sole();
        $this->assertSame($backup->backup_id, $create->args['backup_id']);
        $this->assertSame(['a/a.php'], $create->args['plugins']);
        $this->assertStringContainsString('X-Amz-Signature=up', $create->args['uploads']['db']['url']);
        $this->assertArrayNotHasKey('key', $create->args, 'het panel stuurt geen sleutel');

        $updates = SiteJob::query()->whereIn('command', ['update.plugin', 'update.theme'])->get();
        $this->assertCount(2, $updates);
        $this->assertTrue($updates->every(fn ($j) => $j->available_at->gt(now()->addHours(12))), 'updates wachten op de backup');

        $this->finish($create, $site, ['backup_id' => $backup->backup_id, 'db' => ['tables' => 42], 'db_size' => 3_000_000, 'files_size' => 1_000_000,
            'db_sha256' => str_repeat('a', 64), 'files_sha256' => str_repeat('b', 64)]);
        $backup->refresh();
        $this->assertSame('ready', $backup->status);
        $this->assertTrue($backup->expires_at->between(now()->addDays(6), now()->addDays(8)));
        $this->assertSame(['backup_id' => $backup->backup_id], $create->fresh()->args, 'tijdelijke links weg uit het archief');
        $this->assertTrue(SiteJob::query()->whereIn('command', ['update.plugin', 'update.theme'])->get()->every(fn ($j) => !$j->available_at->isFuture()));

        $page = $this->get('/sites/' . $site->site_id)->assertOk()->assertSee('id="sd-backups"', false)->assertSee('webshop')->assertSee('Bestellingen en klanten van na');

        // Webshop: database alleen met extra bevestiging.
        $this->post("/sites/{$site->site_id}/backups/{$backup->id}/terugzetten", ['database' => 1])->assertSessionHasErrors('backup');
        $this->assertFalse(SiteJob::query()->where('command', 'backup.restore')->exists());
        $this->post("/sites/{$site->site_id}/backups/{$backup->id}/terugzetten", ['database' => 1, 'confirm_orders' => 1])->assertSessionHasNoErrors();
        $restore = SiteJob::query()->where('command', 'backup.restore')->sole();
        $this->assertSame(['files', 'db'], $restore->args['parts']);
        $this->assertSame(['files' => str_repeat('b', 64), 'db' => str_repeat('a', 64)], $restore->args['sha256']);
        $this->assertStringContainsString('X-Amz-Signature=down', $restore->args['downloads']['db']['url']);
        $this->assertSame('restoring', $backup->fresh()->status);

        $this->finish($restore, $site, ['backup_id' => $backup->backup_id, 'parts' => ['files', 'db']]);
        $this->assertNotNull($backup->fresh()->restored_at);
        $this->assertSame('ready', $backup->fresh()->status);
        $this->assertTrue(SiteJob::query()->where('command', 'site.inventory')->where('status', 'pending')->exists());
    }

    public function test_failed_backup_cancels_the_updates(): void
    {
        $site = $this->site(shop: false);
        $this->actingAs($this->makeUser())->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'ids' => ['a/a.php']]);
        SiteJob::query()->where('command', 'backup.create')->update(['status' => 'failed', 'error' => 'Upload naar de opslag mislukt (403)']);

        $this->artisan('ocm:backups')->assertSuccessful();
        $this->assertSame('failed', Backup::query()->value('status'));
        $update = SiteJob::query()->where('command', 'update.plugin')->sole();
        $this->assertSame('rejected', $update->status);
        $this->assertStringContainsString('backup vooraf is mislukt', $update->error);
    }

    public function test_backups_are_deleted_after_seven_days_or_when_the_visual_check_is_approved(): void
    {
        $site = $this->site(shop: false);
        $this->actingAs($this->makeUser())->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'ids' => ['a/a.php']]);
        $backup = Backup::query()->sole();
        $this->finish(SiteJob::query()->where('command', 'backup.create')->sole(), $site, ['backup_id' => $backup->backup_id, 'db_size' => 10, 'files_size' => 10, 'db_sha256' => str_repeat('a', 64), 'files_sha256' => str_repeat('b', 64)]);
        Storage::disk('backups')->put($backup->path('db'), 'x');
        Storage::disk('backups')->put($backup->path('files'), 'x');

        $this->artisan('ocm:backups');
        $this->assertSame('ready', $backup->fresh()->status);

        $this->travel(8)->days();
        $this->artisan('ocm:backups');
        $this->assertSame('deleted', $backup->fresh()->status);
        Storage::disk('backups')->assertMissing($backup->path('db'));
        $this->travelBack();

        // Tweede ronde: "in orde" bij de visuele controle verwijdert direct.
        $site->forceFill(['inventory' => $site->inventory])->save();
        $this->post("/sites/{$site->site_id}/updates", ['type' => 'theme', 'ids' => ['astra']]);
        $second = Backup::query()->where('status', 'pending')->sole();
        $this->finish(SiteJob::query()->where('command', 'backup.create')->where('status', 'pending')->sole(), $site, ['backup_id' => $second->backup_id, 'db_size' => 10, 'files_size' => 10, 'db_sha256' => str_repeat('a', 64), 'files_sha256' => str_repeat('b', 64)]);
        Storage::disk('backups')->put($second->path('db'), 'x');
        $check = VisualCheck::query()->create(['site_id' => $site->id, 'status' => 'done', 'job_ids' => $second->update_job_ids, 'diff_percent' => 40, 'after_at' => now()]);
        $this->post("/sites/{$site->site_id}/visueel/{$check->id}/gezien")->assertRedirect();
        $this->assertSame('deleted', $second->fresh()->status);
        Storage::disk('backups')->assertMissing($second->path('db'));
    }

    public function test_updates_after_a_full_backup_reuse_it(): void
    {
        $site = $this->site('0.8.0', false);
        $this->actingAs($this->makeUser());
        $this->post("/sites/{$site->site_id}/backups/volledig")->assertRedirect()->assertSessionHas('status');
        $full = Backup::query()->sole();
        $create = SiteJob::query()->where('command', 'backup.create')->sole();
        $this->assertTrue($create->args['full']);
        $this->assertFalse($create->available_at->isFuture(), 'volledige backup start meteen');

        // Acht losse updates kort daarna: geen extra backups, wachten op de volledige backup.
        foreach (range(1, 8) as $i) {
            $this->post("/sites/{$site->site_id}/updates", ['type' => $i % 2 ? 'plugin' : 'theme', 'ids' => [$i % 2 ? 'a/a.php' : 'astra']]);
        }
        $this->assertSame(1, Backup::query()->count());
        $this->assertSame(1, SiteJob::query()->where('command', 'backup.create')->count());
        $updates = SiteJob::query()->whereIn('command', ['update.plugin', 'update.theme'])->get();
        $this->assertTrue($updates->every(fn ($j) => $j->available_at->isFuture()));

        $this->finish($create, $site, ['backup_id' => $full->backup_id, 'db_size' => 10, 'files_size' => 900, 'db_sha256' => str_repeat('a', 64), 'files_sha256' => str_repeat('b', 64)]);
        $this->assertTrue(SiteJob::query()->whereIn('command', ['update.plugin', 'update.theme'])->get()->every(fn ($j) => !$j->available_at->isFuture()));
        $this->get('/sites/' . $site->site_id)->assertSee('volledige site (database en alle bestanden)')->assertSee('gebruiken deze back-up');

        // Nieuwe update terwijl de volledige backup klaar is: direct door.
        $site->forceFill(['inventory' => $site->inventory])->save();
        SiteJob::query()->whereIn('command', ['update.plugin', 'update.theme'])->update(['status' => 'done']);
        $this->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'ids' => ['a/a.php']]);
        $this->assertFalse(SiteJob::query()->where('command', 'update.plugin')->where('status', 'pending')->sole()->available_at->isFuture());

        // Na de bewaartermijn voor hergebruik weer een eigen backup.
        $this->travel(4)->hours();
        SiteJob::query()->where('command', 'update.plugin')->update(['status' => 'done']);
        $this->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'ids' => ['a/a.php']]);
        $this->assertSame(2, Backup::query()->count());
    }

    public function test_older_plugins_update_without_backup(): void
    {
        $site = $this->site('0.4.0', false);
        $this->actingAs($this->makeUser())->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'ids' => ['a/a.php']]);
        $this->assertSame(0, Backup::query()->count());
        $this->assertFalse(SiteJob::query()->where('command', 'update.plugin')->sole()->available_at->isFuture());
        $this->get('/sites/' . $site->site_id)->assertSee('Vanaf client-plugin 0.5.0');
    }

    public function test_background_backup_from_0_14_completes_via_the_backup_created_event(): void
    {
        $site = $this->site('0.14.0', false);
        $this->actingAs($this->makeUser());
        $this->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'ids' => ['a/a.php']]);
        $backup = Backup::query()->sole();
        $create = SiteJob::query()->where('command', 'backup.create')->sole();
        $this->assertTrue($create->args['async']);

        // De site accepteert en sluit de verbinding; de backup loopt nog.
        $this->finish($create, $site, ['backup_id' => $backup->backup_id, 'accepted' => true]);
        $create->update(['result' => ['backup_id' => $backup->backup_id, 'accepted' => true]]);
        $this->assertSame('pending', $backup->fresh()->status);
        $this->assertSame(['backup_id' => $backup->backup_id], $create->fresh()->args);
        $update = SiteJob::query()->where('command', 'update.plugin')->sole();
        $this->assertTrue($update->available_at->isFuture());

        \App\Ocm\Backups::tick();
        $this->assertSame('pending', $backup->fresh()->status);
        $this->assertSame(1, SiteJob::query()->where('command', 'site.info')->where('status', 'pending')->count(), 'heartbeat om events op te halen');
        $this->assertSame('pending', $update->fresh()->status);

        (new \App\Ocm\EventIngestor())->ingest($site, [[
            'seq' => 1, 'time' => time(), 'type' => 'backup.created', 'object' => $backup->backup_id, 'from' => null, 'to' => null, 'result' => 'success', 'source' => 'nb-ocm', 'user_login' => '',
            'data' => ['tables' => 40, 'db_size' => 2_000_000, 'files_size' => 500_000, 'db_sha256' => str_repeat('a', 64), 'files_sha256' => str_repeat('b', 64), 'seconds' => 312.5],
        ]]);
        \App\Ocm\Backups::tick();
        $backup->refresh();
        $this->assertSame('ready', $backup->status);
        $this->assertSame(40, $backup->tables);
        $this->assertSame(str_repeat('b', 64), $backup->files_sha256);
        $this->assertFalse($update->fresh()->available_at->isFuture(), 'update vrijgegeven');
    }

    public function test_failed_background_backup_rejects_the_updates(): void
    {
        $site = $this->site('0.14.0', false);
        $this->actingAs($this->makeUser());
        $this->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'ids' => ['a/a.php']]);
        $backup = Backup::query()->sole();
        $create = SiteJob::query()->where('command', 'backup.create')->sole();
        $create->update(['status' => 'done', 'finished_at' => now(), 'result' => ['backup_id' => $backup->backup_id, 'accepted' => true]]);

        (new \App\Ocm\EventIngestor())->ingest($site, [[
            'seq' => 1, 'time' => time(), 'type' => 'backup.created', 'object' => $backup->backup_id, 'from' => null, 'to' => null, 'result' => 'failed', 'source' => 'nb-ocm', 'user_login' => '',
            'data' => ['message' => 'Upload naar de opslag mislukt (HTTP 403)'],
        ]]);
        \App\Ocm\Backups::tick();
        $this->assertSame('failed', $backup->fresh()->status);
        $update = SiteJob::query()->where('command', 'update.plugin')->sole();
        $this->assertSame('rejected', $update->status);
        $this->assertStringContainsString('HTTP 403', $update->error);
    }

    public function test_older_plugins_get_no_async_flag(): void
    {
        $site = $this->site('0.13.0', false);
        $this->actingAs($this->makeUser());
        $this->post("/sites/{$site->site_id}/updates", ['type' => 'plugin', 'ids' => ['a/a.php']]);
        $this->assertArrayNotHasKey('async', SiteJob::query()->where('command', 'backup.create')->sole()->args);
    }
}
