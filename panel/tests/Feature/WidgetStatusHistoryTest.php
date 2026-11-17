<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MaintenanceRun;
use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\Monitoring\Monitor;
use App\Ocm\SiteSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WidgetStatusHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Monitor::$sslProbe = null;
        parent::tearDown();
    }

    /** @return array{0: Site, 1: Customer} */
    private function setUpCustomer(string $version, bool $widgetReport = true): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $site = $this->makePairedSite();
        $customer = Customer::query()->create(['name' => 'Bakkerij', 'email' => 'a@bakkerij.example', 'visits_per_year' => 4, 'contract_start' => '2026-02-17', 'widget_report' => $widgetReport]);
        $site->forceFill(['plugin_version' => $version, 'last_seen_at' => now(), 'customer_id' => $customer->id])->save();

        return [$site->fresh(), $customer->fresh()];
    }

    public function test_old_plugins_get_no_status_or_reports(): void
    {
        [$site, $customer] = $this->setUpCustomer('0.15.9');
        MaintenanceRun::query()->create(['customer_id' => $customer->id, 'due_on' => '2026-11-17', 'done_at' => now()]);

        $args = SiteSchedule::args($customer, $site);
        $this->assertArrayNotHasKey('status', $args);
        $this->assertArrayNotHasKey('reports', $args);
        $this->assertArrayHasKey('report_url', $args);
        $this->assertArrayNotHasKey('status', SiteSchedule::args($customer), 'zonder site: alleen de oude velden');

        SiteSchedule::push($site);
        $this->assertArrayNotHasKey('status', SiteJob::query()->where('command', 'site.schedule')->sole()->args);
    }

    public function test_new_plugins_get_status_and_report_history(): void
    {
        [$site, $customer] = $this->setUpCustomer('0.16.0');
        foreach (['2026-02-17', '2026-05-17', '2026-08-17', '2026-11-17'] as $due) {
            MaintenanceRun::query()->create(['customer_id' => $customer->id, 'due_on' => $due, 'done_at' => now()]);
        }
        MaintenanceRun::query()->create(['customer_id' => $customer->id, 'due_on' => '2027-02-17']); // nog niet afgerond
        $site->forceFill([
            'ssl_checked_at' => now(), 'ssl_expires_at' => now()->addDays(60), 'ssl_error' => null,
            'uptime_state' => 'up', 'uptime_since' => now()->subDays(40), 'uptime_checked_at' => now(),
        ])->save();

        $args = SiteSchedule::args($customer, $site->fresh());
        $this->assertSame(['ok' => true, 'expires' => '2027-01-19'], $args['status']['ssl']);
        $this->assertTrue($args['status']['uptime']['ok']);
        $this->assertSame(100.0, $args['status']['uptime']['percent']);
        $this->assertNull($args['status']['uptime']['down_since']);

        $this->assertCount(4, $args['reports']);
        $this->assertSame(['2026-11-17', '2026-08-17', '2026-05-17', '2026-02-17'], array_column($args['reports'], 'date'), 'nieuwste eerst, alleen afgerond');
        $this->assertSame('Onderhoud november 2026', $args['reports'][0]['label']);
        foreach ($args['reports'] as $report) {
            $this->assertStringStartsWith('https://ocm.test/api/rapport/' . $customer->id . '/', $report['url']);
        }

        // Elke link levert het rapport; de link van de ene beurt werkt niet voor een andere.
        $path = substr($args['reports'][1]['url'], strlen('https://ocm.test'));
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'inline; filename="onderhoudsrapport-bakkerij-2026-08-17.pdf"');
        $runId = (int) explode('?', basename(parse_url($path, PHP_URL_PATH)))[0];
        $this->get(str_replace('/' . $runId . '?', '/' . ($runId + 1) . '?', $path))->assertForbidden();
        $this->get('/api/rapport/' . $customer->id . '/' . $runId)->assertForbidden(); // zonder handtekening

        // Uitgeschakeld: geen historie en de links werken niet meer.
        $customer->update(['widget_report' => false]);
        $this->assertSame([], SiteSchedule::args($customer->fresh(), $site)['reports']);
        $this->get($path)->assertNotFound();
    }

    public function test_run_link_cannot_be_used_for_another_customers_or_open_run(): void
    {
        [$site, $customer] = $this->setUpCustomer('0.16.0');
        $other = Customer::query()->create(['name' => 'Ander', 'email' => 'x@ander.example', 'visits_per_year' => 4, 'widget_report' => true]);
        $foreign = MaintenanceRun::query()->create(['customer_id' => $other->id, 'due_on' => '2026-11-01', 'done_at' => now()]);
        $open = MaintenanceRun::query()->create(['customer_id' => $customer->id, 'due_on' => '2026-11-17']);

        $this->assertNull(\App\Http\Controllers\PublicReportController::runUrl($customer, $foreign));
        $this->assertNull(\App\Http\Controllers\PublicReportController::runUrl($customer, $open));

        // Ook met een geldige handtekening: niet van deze klant of niet afgerond = 404.
        foreach ([$foreign, $open] as $run) {
            $signed = \Illuminate\Support\Facades\URL::temporarySignedRoute('report.public.run', now()->addDay(), ['customer' => $customer->id, 'run' => $run->id], false);
            $this->get($signed)->assertNotFound();
        }
        // Verlopen handtekening.
        $done = MaintenanceRun::query()->create(['customer_id' => $customer->id, 'due_on' => '2026-08-17', 'done_at' => now()]);
        $url = \App\Http\Controllers\PublicReportController::runUrl($customer, $done);
        $this->travel(41)->days();
        $this->get(substr($url, strlen('https://ocm.test')))->assertForbidden();
    }

    public function test_down_site_reports_down_since(): void
    {
        [$site, $customer] = $this->setUpCustomer('0.16.0');
        $site->forceFill(['uptime_state' => 'down', 'down_since' => now()->subMinutes(30), 'uptime_since' => now()->subDays(10),
            'ssl_checked_at' => now(), 'ssl_expires_at' => now()->subDay(), 'ssl_error' => 'Certificaat niet geldig'])->save();
        \App\Models\UptimeIncident::query()->create(['site_id' => $site->id, 'started_at' => now()->subMinutes(30)]);

        $status = SiteSchedule::status($site->fresh());
        $this->assertFalse($status['ssl']['ok']);
        $this->assertFalse($status['uptime']['ok']);
        $this->assertSame(now()->subMinutes(30)->toIso8601String(), $status['uptime']['down_since']);
        $this->assertLessThan(100.0, $status['uptime']['percent']);
    }

    public function test_monitor_pushes_widget_on_state_changes_only_for_new_plugins(): void
    {
        [$site] = $this->setUpCustomer('0.16.0');
        $old = $site->replicate();
        $old->forceFill(['site_id' => 'site_old', 'url' => 'https://oud.example.nl', 'host' => 'oud.example.nl', 'key_id' => 'k_old', 'plugin_version' => '0.15.0'])->save();
        Monitor::$sslProbe = fn (string $host) => ['expires_at' => now()->addDays(80)->timestamp, 'issuer' => "Let's Encrypt", 'error' => null];
        // Per ronde twee sites: twee keer online, daarna twee keer offline.
        Http::fake(['*' => Http::sequence()->push('ok', 200)->push('ok', 200)->push('ok', 200)->push('ok', 200)
            ->push('fout', 503)->push('fout', 503)->push('fout', 503)->push('fout', 503)]);

        $this->artisan('ocm:monitor')->assertSuccessful(); // eerste meting: up + SSL bekend
        $jobs = SiteJob::query()->where('command', 'site.schedule');
        $this->assertSame(1, (clone $jobs)->where('site_id', $site->id)->count());
        $this->assertSame(0, (clone $jobs)->where('site_id', $old->id)->count(), '0.15.x krijgt geen extra opdrachten');
        $this->assertTrue((clone $jobs)->sole()->args['status']['ssl']['ok']);

        // Nog een geslaagde meting: niets veranderd, geen nieuwe opdracht (en de klaarstaande blijft één).
        $this->travel(5)->minutes();
        $this->artisan('ocm:monitor')->assertSuccessful();
        $this->assertSame(1, (clone $jobs)->count());

        // Offline (na twee mislukte metingen): de klaarstaande opdracht krijgt de nieuwe status.
        foreach ([1, 2] as $i) {
            $this->travel(5)->minutes();
            $this->artisan('ocm:monitor')->assertSuccessful();
        }
        $job = (clone $jobs)->sole();
        $this->assertFalse($job->args['status']['uptime']['ok']);
        $this->assertNotNull($job->args['status']['uptime']['down_since']);
    }
}
