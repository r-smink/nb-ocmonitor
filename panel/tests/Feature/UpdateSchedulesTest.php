<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\SiteJob;
use App\Models\Tag;
use App\Models\UpdateSchedule;
use App\Models\VisualCheck;
use App\Ocm\UpdateSchedules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UpdateSchedulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ocm.visual.enabled' => false, 'ocm.backups.enabled' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function siteWithUpdates()
    {
        $site = $this->makePairedSite();
        $site->plugin_version = '0.8.0';
        $site->last_seen_at = now();
        $site->inventory = ['plugins' => [
            ['file' => 'akismet/akismet.php', 'slug' => 'akismet', 'name' => 'Akismet', 'version' => '5.3', 'active' => true, 'update' => ['version' => '5.4']],
            ['file' => 'elementor/elementor.php', 'slug' => 'elementor', 'name' => 'Elementor', 'version' => '3.1', 'active' => true, 'update' => ['version' => '3.2']],
        ]];
        $site->save();

        return $site;
    }

    private function schedule(array $attrs = []): UpdateSchedule
    {
        return UpdateSchedule::query()->create($attrs + [
            'name' => 'Dinsdag', 'enabled' => true, 'scope' => 'all', 'scope_ids' => [], 'types' => ['plugin'],
            'days' => [2], 'time' => '06:00', 'exclude' => ['elementor'],
        ]);
    }

    public function test_runs_once_at_the_planned_time_and_respects_exclusions(): void
    {
        $this->siteWithUpdates();
        $schedule = $this->schedule();

        // Dinsdag 3 november 2026, 05:55 Nederlandse tijd = 04:55 UTC: nog niet.
        $this->assertSame([], UpdateSchedules::runDue(CarbonImmutable::parse('2026-11-03 04:55:00')));
        $result = UpdateSchedules::runDue(CarbonImmutable::parse('2026-11-03 05:05:00'))[$schedule->id];
        $this->assertSame(1, $result['queued']);
        $this->assertSame(['Klant: Elementor'], $result['excluded']);

        $job = SiteJob::query()->where('command', 'update.plugin')->sole();
        $this->assertSame(['akismet/akismet.php'], $job->args['plugins']);
        $this->assertSame($schedule->id, $job->update_schedule_id);
        $this->assertNull($job->requested_by);

        // Niet nog een keer hetzelfde moment.
        $this->assertSame([], UpdateSchedules::runDue(CarbonImmutable::parse('2026-11-03 05:30:00')));
        // Te laat (scheduler lag 3 uur stil): niet meer inhalen.
        $schedule->forceFill(['last_run_at' => null])->save();
        $this->assertSame([], UpdateSchedules::runDue(CarbonImmutable::parse('2026-11-03 08:30:00')));
    }

    public function test_pause_scope_and_security_only(): void
    {
        $site = $this->siteWithUpdates();
        $paused = $this->schedule(['paused_until' => '2026-11-30']);
        $this->assertFalse(UpdateSchedules::isDue($paused, CarbonImmutable::parse('2026-11-03 05:05:00')));
        $this->assertSame('2026-12-01', $paused->nextRun(CarbonImmutable::parse('2026-11-03 05:05:00'))->toDateString());

        $tag = Tag::query()->create(['name' => 'webshop', 'color' => 'blue']);
        $tagged = $this->schedule(['scope' => 'tags', 'scope_ids' => [$tag->id], 'exclude' => []]);
        $this->assertSame(0, UpdateSchedules::run($tagged)['sites']);
        $site->tags()->attach($tag);
        $this->assertSame(1, UpdateSchedules::run($tagged->fresh())['sites']);

        SiteJob::query()->delete();
        $security = $this->schedule(['security_only' => true, 'exclude' => []]);
        $this->assertSame(0, UpdateSchedules::run($security)['queued'], 'geen bekende kwetsbaarheden, dus niets');
    }

    public function test_blocked_sites_are_skipped(): void
    {
        $site = $this->siteWithUpdates();
        $site->plugin_version = '0.1.0';
        $site->save();
        $result = UpdateSchedules::run($this->schedule());

        $this->assertSame(0, $result['queued']);
        $this->assertArrayHasKey('Klant', $result['skipped']);
    }

    public function test_visual_change_after_scheduled_updates_restores_files_only_when_enabled(): void
    {
        config(['ocm.visual.threshold_percent' => 15]);
        Storage::fake('backups')->buildTemporaryUrlsUsing(fn ($path) => 'https://spaces.test/' . $path . '?sig=x');
        $site = $this->siteWithUpdates();
        $schedule = $this->schedule(['rollback_on_visual' => true, 'exclude' => []]);
        UpdateSchedules::run($schedule);
        $jobIds = SiteJob::query()->pluck('id')->all();
        $backup = Backup::query()->create(['backup_id' => 'bk_1', 'site_id' => $site->id, 'status' => 'ready', 'components' => ['plugins' => ['akismet/akismet.php']],
            'update_job_ids' => $jobIds, 'files_sha256' => str_repeat('a', 64), 'db_sha256' => str_repeat('b', 64)]);
        $check = VisualCheck::query()->create(['site_id' => $site->id, 'status' => 'done', 'job_ids' => $jobIds, 'diff_percent' => 40]);

        $job = UpdateSchedules::afterVisualCheck($check);

        $this->assertNotNull($job);
        $this->assertSame('backup.restore', $job->command);
        $this->assertSame(['files'], $job->args['parts']);
        $this->assertSame('restoring', $backup->fresh()->status);

        // Zonder de optie: niets terugzetten.
        $schedule->update(['rollback_on_visual' => false]);
        $backup->update(['status' => 'ready']);
        $this->assertNull(UpdateSchedules::afterVisualCheck($check));
        // Kleine wijziging: niets.
        $schedule->update(['rollback_on_visual' => true]);
        $check->update(['diff_percent' => 3]);
        $this->assertNull(UpdateSchedules::afterVisualCheck($check));
    }

    public function test_pages_and_permissions(): void
    {
        $this->siteWithUpdates();
        $this->actingAs($this->makeUser('beheerder'));
        $this->get('/updates/automatisch')->assertOk()->assertSee('Nog geen schema');
        $this->get('/updates/automatisch/nieuw')->assertOk();
        $this->post('/updates/automatisch', ['name' => 'Wekelijks', 'enabled' => 1, 'scope' => 'all', 'types' => ['plugin', 'theme'], 'days' => [2, 4], 'time' => '06:30', 'exclude' => "Elementor-Pro, woocommerce\nacf"])
            ->assertRedirect('/updates/automatisch');
        $schedule = UpdateSchedule::query()->sole();
        $this->assertSame(['elementor-pro', 'woocommerce', 'acf'], $schedule->exclude);
        $this->assertSame([2, 4], $schedule->days);
        $this->get('/updates/automatisch')->assertOk()->assertSee('Wekelijks')->assertSee('di, do om 06:30');
        $this->post('/updates/automatisch', ['name' => 'X', 'scope' => 'tags', 'types' => ['plugin'], 'days' => [1], 'time' => '06:00'])->assertSessionHasErrors('scope');
        $this->post('/updates/automatisch/' . $schedule->id . '/nu')->assertRedirect();
        $this->assertTrue(SiteJob::query()->where('update_schedule_id', $schedule->id)->exists());
    }

    public function test_viewer_can_look_but_not_change(): void
    {
        $schedule = $this->schedule();
        $this->actingAs($this->makeUser('viewer'));
        $this->get('/updates/automatisch')->assertOk()->assertSee('Dinsdag')->assertDontSee('Nu uitvoeren');
        $this->post('/updates/automatisch/' . $schedule->id . '/nu')->assertForbidden();
        $this->delete('/updates/automatisch/' . $schedule->id)->assertForbidden();
    }
}
