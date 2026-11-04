<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Ocm\Monitoring\Incidents;
use App\Ocm\Reports\ReportData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UptimeIncidentsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_period_only_counts_time_since_monitoring_started(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $site = $this->makePairedSite();
        $site->forceFill(['uptime_since' => '2026-11-10 12:00:00'])->save();
        // 10 dagen gemeten, 144 minuten offline = 1%
        UptimeIncident::query()->create(['site_id' => $site->id, 'started_at' => '2026-11-15 10:00:00', 'ended_at' => '2026-11-15 12:24:00', 'cause' => 'HTTP 502']);

        $p = Incidents::period($site->fresh(), CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30 23:59:59'));
        $this->assertSame(99.0, $p['percent']);
        $this->assertSame(144, $p['down_minutes']);
        $this->assertSame(1, $p['incidents']);

        // Vóór het begin van de meting is er geen uptime bekend.
        $this->assertNull(Incidents::period($site->fresh(), CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31 23:59:59'))['percent']);
    }

    public function test_incident_spanning_two_months_is_split(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $site = $this->makePairedSite();
        $site->forceFill(['uptime_since' => '2026-09-01 00:00:00'])->save();
        UptimeIncident::query()->create(['site_id' => $site->id, 'started_at' => '2026-10-31 22:00:00', 'ended_at' => '2026-11-01 00:00:00']); // UTC; 23:00-01:00 Nederlandse tijd

        $months = collect(Incidents::monthly($site->fresh(), 3))->keyBy(fn ($m) => $m['month']->format('Y-m'));
        $this->assertSame(60, $months['2026-10']['down_minutes']);
        $this->assertSame(60, $months['2026-11']['down_minutes']);
        $this->assertSame(0, $months['2026-09']['down_minutes']);
        $this->assertSame(100.0, $months['2026-09']['percent']);
    }

    public function test_backfill_derives_incidents_from_existing_checks(): void
    {
        $site = $this->makePairedSite();
        $t = CarbonImmutable::parse('2026-11-01 00:00:00');
        foreach ([true, false, true, false, false, false, true, false] as $i => $ok) {
            UptimeCheck::query()->create(['site_id' => $site->id, 'checked_at' => $t->addMinutes($i * 5), 'ok' => $ok, 'error' => $ok ? null : 'HTTP 500']);
        }
        UptimeIncident::query()->delete();

        Incidents::backfill();

        // Eén losse fout telt niet; drie op rij wel (van 00:15 tot 00:30). De laatste losse fout ook niet.
        $incident = UptimeIncident::query()->sole();
        $this->assertSame('2026-11-01 00:15:00', $incident->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 00:30:00', $incident->ended_at->format('Y-m-d H:i:s'));
        $this->assertSame('HTTP 500', $incident->cause);
        $this->assertSame('2026-11-01 00:00:00', $site->fresh()->uptime_since->format('Y-m-d H:i:s'));
    }

    public function test_site_page_and_report_show_incidents(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $site = $this->makePairedSite();
        $customer = Customer::query()->create(['name' => 'Bakkerij', 'email' => 'info@bakkerij.test']);
        $site->forceFill(['uptime_since' => '2026-11-01 00:00:00', 'customer_id' => $customer->id])->save();
        UptimeIncident::query()->create(['site_id' => $site->id, 'started_at' => '2026-11-15 03:10:00', 'ended_at' => '2026-11-15 04:35:00', 'cause' => 'cURL error 28: timeout']);

        $this->actingAs($this->makeUser())->get('/sites/' . $site->site_id)->assertOk()
            ->assertSee('id="beschikbaarheid"', false)->assertSee('1 u 25 min')->assertSee('cURL error 28: timeout');

        $data = ReportData::build($customer, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-20'));
        $uptime = $data['sites'][0]['uptime'];
        $this->assertSame(1, $uptime['incidents']);
        $this->assertSame(85, $uptime['down_minutes']);
        $this->assertSame('1 u 25 min', $uptime['list'][0]['duration']);
    }
}
