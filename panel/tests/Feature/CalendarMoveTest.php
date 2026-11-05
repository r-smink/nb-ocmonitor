<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MaintenanceRun;
use App\Models\SiteJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarMoveTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        // Elke maand op de 5e: 5 okt, 5 nov, 5 dec (zaterdag → 7 dec), 5 jan 2027, ...
        return Customer::query()->create(['name' => 'Bakkerij', 'email' => 'a@bakkerij.example', 'visits_per_year' => 12, 'contract_start' => '2026-10-05']);
    }

    public function test_one_visit_moves_without_changing_the_following_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00'));
        $customer = $this->customer();
        $this->actingAs($this->makeUser());

        $this->post("/klanten/{$customer->id}/onderhoud/verplaatsen", ['due_on' => '2026-11-05', 'planned_on' => '2026-11-12'])->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'verplaatst naar donderdag 12 november 2026') && str_contains($s, 'volgende datums blijven hetzelfde'));
        $this->assertSame('2026-11-12', MaintenanceRun::query()->sole()->planned_on->toDateString());

        $customer->load('runs');
        $dates = collect($customer->plannedDates(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-01-31')))->map(fn ($i) => $i['date']->toDateString())->all();
        $this->assertSame(['2026-11-12', '2026-12-07', '2027-01-05'], $dates, 'alleen november is verschoven');
        $this->assertSame('2026-11-12', $customer->plannedOn(CarbonImmutable::parse('2026-11-05'))->toDateString(), 'de beurt houdt zijn contractdatum als sleutel');

        $page = $this->get('/klanten')->assertOk();
        $page->assertSeeInOrder(['data-cal-drop="2026-11-12"', 'data-due="2026-11-05"'], false);
        $page->assertSee('cal-item planned moved', false)->assertSee('verplaatst van 05-11');
        // 5 okt is nog open (achterstallig) en blijft de eerstvolgende beurt; in de planning staat november verplaatst.
        $this->get('/klanten/' . $customer->id)->assertOk()->assertSee('(contract: 05-11)');

        // Afronden gaat via de contractdatum; het verplaatste moment telt dan als afgerond.
        $this->post("/klanten/{$customer->id}/onderhoud", ['due_on' => '2026-11-05'])->assertRedirect();
        $this->post("/klanten/{$customer->id}/onderhoud/verplaatsen", ['due_on' => '2026-11-05', 'planned_on' => '2026-11-20'])->assertSessionHasErrors('planned_on');

        // Terug naar de contractdatum haalt de verplaatsing weg.
        $this->post("/klanten/{$customer->id}/onderhoud/verplaatsen", ['due_on' => '2026-12-07', 'planned_on' => '2026-12-10']);
        $this->post("/klanten/{$customer->id}/onderhoud/verplaatsen", ['due_on' => '2026-12-07', 'planned_on' => '2026-12-07'])->assertSessionHas('status', fn ($s) => str_contains($s, 'weer op de contractdatum'));
        $this->assertNull(MaintenanceRun::query()->where('due_on', '2026-12-07')->value('planned_on'));
    }

    public function test_only_real_contract_dates_and_only_for_managers(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->makeUser());
        $this->post("/klanten/{$customer->id}/onderhoud/verplaatsen", ['due_on' => '2026-11-06', 'planned_on' => '2026-11-12'])->assertSessionHasErrors('planned_on');
        $this->post("/klanten/{$customer->id}/onderhoud/verplaatsen", ['due_on' => 'morgen', 'planned_on' => '2026-11-12'])->assertSessionHasErrors('due_on');
        $this->assertSame(0, MaintenanceRun::query()->count());
    }

    public function test_viewer_cannot_move_and_sees_no_drag_handles(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00'));
        $customer = $this->customer();
        $this->actingAs($this->makeUser('viewer'));
        $this->get('/klanten')->assertOk()->assertDontSee('draggable="true"', false)->assertDontSee('calMoveForm', false);
        $this->post("/klanten/{$customer->id}/onderhoud/verplaatsen", ['due_on' => '2026-11-05', 'planned_on' => '2026-11-12'])->assertForbidden();
    }

    public function test_dashboard_widget_gets_the_moved_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00'));
        $customer = $this->customer();
        $site = $this->makePairedSite();
        $site->forceFill(['plugin_version' => '0.11.0', 'customer_id' => $customer->id])->save();
        $this->actingAs($this->makeUser());
        $this->post("/klanten/{$customer->id}/onderhoud/verplaatsen", ['due_on' => '2026-11-05', 'planned_on' => '2026-11-12']);
        $this->assertSame('2026-11-12', SiteJob::query()->where('command', 'site.schedule')->latest('id')->firstOrFail()->args['next']);
    }
}
