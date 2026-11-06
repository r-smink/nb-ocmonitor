<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MaintenanceRun;
use App\Models\PhpError;
use App\Models\Site;
use App\Models\SiteJob;
use App\Models\UpdateSchedule;
use App\Ocm\Signer\ResultProcessor;
use App\Ocm\SiteSchedule;
use App\Ocm\UpdateSchedules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetErrorsSchedulesTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $version = '0.12.0'): Site
    {
        $site = $this->makePairedSite();
        $site->forceFill(['plugin_version' => $version, 'last_seen_at' => now(), 'inventory' => [
            'core' => ['version' => '6.9'],
            'plugins' => [
                ['file' => 'a/a.php', 'slug' => 'a', 'name' => 'Plugin A', 'version' => '1', 'update' => ['version' => '2']],
                ['file' => 'b/b.php', 'slug' => 'b', 'name' => 'Plugin B', 'version' => '1', 'update' => ['version' => '2']],
            ],
            'themes' => [['stylesheet' => 'astra', 'name' => 'Astra', 'version' => '4', 'update' => ['version' => '5']]],
        ]])->save();

        return $site;
    }

    public function test_schedule_can_run_only_selected_plugins(): void
    {
        $site = $this->site();
        $this->actingAs($this->makeUser());
        $base = ['name' => 'Wekelijks', 'enabled' => 1, 'scope' => 'all', 'types' => ['plugin', 'theme'], 'days' => [2], 'time' => '03:00'];

        $this->get('/updates/automatisch/nieuw')->assertOk()->assertSee('Alleen deze plugins en thema')->assertSee('Plugin A')->assertSee('Astra');
        $this->post('/updates/automatisch', $base + ['only_mode' => 'selected'])->assertSessionHasErrors('include');
        $this->post('/updates/automatisch', $base + ['only_mode' => 'selected', 'include' => ['a', 'astra']])->assertSessionHasNoErrors();
        $schedule = UpdateSchedule::query()->sole();
        $this->assertSame(['a', 'astra'], $schedule->include);

        UpdateSchedules::run($schedule);
        $plugin = SiteJob::query()->where('command', 'update.plugin')->sole();
        $this->assertSame(['a/a.php'], $plugin->args['plugins'], 'Plugin B valt buiten "alleen deze"');
        $this->assertTrue(SiteJob::query()->where('command', 'update.theme')->exists());
        $this->get('/updates/automatisch')->assertSee('alleen: a, astra');

        // Terug naar "alle": de keuze vervalt.
        $this->put("/updates/automatisch/{$schedule->id}", $base + ['only_mode' => 'all', 'include' => ['a']]);
        $this->assertSame([], $schedule->fresh()->include);
    }

    public function test_php_errors_are_collected_and_can_be_resolved(): void
    {
        $site = $this->site();
        $info = SiteJob::query()->create(['job_id' => 'job_info', 'site_id' => $site->id, 'command' => 'site.info', 'args' => [], 'status' => 'done', 'available_at' => now()]);
        app(ResultProcessor::class)->process($info, $site, ['wp_version' => '6.9', 'php_version' => '8.3', 'plugin_version' => '0.12.0', 'last_event_seq' => 0, 'php_errors_at' => time() - 60]);
        $list = SiteJob::query()->where('command', 'errors.list')->sole();

        app(ResultProcessor::class)->process($list, $site->fresh(), ['last_at' => time() - 60, 'debug_log' => ['path' => 'debug.log', 'size' => 5_242_880], 'items' => [
            ['hash' => 'abcdef0123456789', 'type' => 'Fatal error', 'message' => 'Uncaught Error: Call to undefined function foo()', 'file' => 'wp-content/plugins/x/x.php', 'line' => 12, 'url' => '/winkel/', 'count' => 7, 'first_seen' => time() - 3600, 'last_seen' => time() - 60],
            ['hash' => 'NIET-GELDIG', 'message' => 'x'],
        ]]);
        $this->assertSame(1, PhpError::query()->count());
        $site->refresh();
        $this->assertContains('1 PHP-fout (7x in 24 uur)', collect($site->issues())->pluck('text')->all());

        // Geen nieuwe fouten: geen nieuwe ophaalopdracht.
        $list->update(['status' => 'done']);
        app(ResultProcessor::class)->process($info, $site, ['wp_version' => '6.9', 'php_version' => '8.3', 'plugin_version' => '0.12.0', 'last_event_seq' => 0, 'php_errors_at' => time() - 60]);
        $this->assertSame(1, SiteJob::query()->where('command', 'errors.list')->count());

        $this->actingAs($this->makeUser());
        $this->get('/sites/' . $site->site_id)->assertOk()->assertSee('PHP-fouten')->assertSee('Call to undefined function foo()')->assertSee('wp-content/plugins/x/x.php:12')->assertSee('5,0 MB');
        $error = PhpError::query()->sole();
        $this->post("/php-fouten/{$error->id}/opgelost")->assertRedirect();
        $this->assertFalse($error->fresh()->isOpen());
        $this->assertNotContains('1 PHP-fout (7x in 24 uur)', collect($site->fresh()->issues())->pluck('text')->all());
    }

    public function test_widget_gets_branding_and_a_signed_report_link_when_enabled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $site = $this->site();
        $customer = Customer::query()->create(['name' => 'Bakkerij', 'email' => 'a@bakkerij.example', 'visits_per_year' => 4, 'contract_start' => '2026-08-17']);
        $site->forceFill(['customer_id' => $customer->id])->save();
        MaintenanceRun::query()->create(['customer_id' => $customer->id, 'due_on' => '2026-11-17', 'done_at' => now()]);

        $args = SiteSchedule::args($customer->fresh());
        $this->assertArrayNotHasKey('report_url', $args, 'staat standaard uit');
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $args['color']);

        $customer->update(['widget_report' => true]);
        $args = SiteSchedule::args($customer->fresh());
        $this->assertStringStartsWith('https://ocm.test/api/rapport/' . $customer->id . '?expires=', $args['report_url']);
        $this->assertSame('Rapport november 2026 downloaden', $args['report_label']);

        $path = substr($args['report_url'], strlen('https://ocm.test'));
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/api/rapport/' . $customer->id)->assertForbidden(); // zonder handtekening
        // Een andere klant in dezelfde link: geweigerd (onbekende klant = 404, bestaande klant = ongeldige handtekening).
        $this->assertContains($this->get(str_replace('/rapport/' . $customer->id, '/rapport/' . ($customer->id + 1), $path))->status(), [403, 404]);
        $other = Customer::query()->create(['name' => 'Ander', 'email' => 'x@ander.example', 'visits_per_year' => 0, 'widget_report' => true]);
        $this->get(str_replace('/rapport/' . $customer->id, '/rapport/' . $other->id, $path))->assertForbidden();

        $customer->update(['widget_report' => false]);
        $this->get($path)->assertNotFound();
    }
}
