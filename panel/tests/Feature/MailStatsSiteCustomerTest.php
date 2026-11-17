<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SiteJob;
use App\Ocm\JobQueue;
use App\Ocm\MailStats;
use App\Ocm\Reports\ReportData;
use App\Ocm\Signer\ResultProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MailStatsSiteCustomerTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): Customer
    {
        return Customer::query()->create($attributes + [
            'name' => 'Bakkerij De Korst', 'contact_name' => 'Anna', 'email' => 'anna@korst.example',
            'visits_per_year' => 4, 'contract_start' => '2026-01-15', 'report_enabled' => true,
        ]);
    }

    public function test_inventory_stores_daily_mail_counts_and_keeps_the_highest(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00'));
        $site = $this->makePairedSite();
        $job = JobQueue::enqueue($site, 'site.inventory');
        $processor = app(ResultProcessor::class);

        $processor->process($job, $site, ['smtp' => ['enabled' => true, 'mail_daily' => [
            ['2026-11-15', 10, 1], ['2026-11-16', 20, 0], ['2026-11-17', 3, 0], ['nonsense', 5, 5], ['2027-01-01', 9, 9],
        ]]]);
        $this->assertSame(3, DB::table('site_mail_stats')->count());
        $this->assertArrayNotHasKey('mail_daily', $site->fresh()->inventory['smtp'], 'niet in de inventaris-JSON');

        // Later: vandaag is gegroeid, en de log op de site is geleegd (nullen) — de oude cijfers blijven.
        $processor->process($job, $site->fresh(), ['smtp' => ['enabled' => true, 'mail_daily' => [['2026-11-15', 0, 0], ['2026-11-16', 0, 0], ['2026-11-17', 7, 2]]]]);
        $sum = MailStats::summary($site, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-17'));
        $this->assertSame(37, $sum['sent']);
        $this->assertSame(3, $sum['failed']);
        $this->assertSame('2026-11-15', $sum['since']->toDateString(), 'cijfers pas vanaf 15 nov bekend');
    }

    public function test_report_shows_mail_counts_for_the_chosen_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00'));
        $site = $this->makePairedSite();
        $other = \App\Models\Site::query()->create(['site_id' => 'site_test02', 'name' => 'Zonder SMTP', 'url' => 'https://zonder-smtp.example.nl', 'host' => 'zonder-smtp.example.nl']);
        $customer = $this->customer(['report_sections' => ['summary', 'mail'], 'report_mail_period' => 'week']);
        $site->forceFill(['customer_id' => $customer->id, 'inventory' => ['smtp' => ['enabled' => true]]])->save();
        $other->forceFill(['customer_id' => $customer->id, 'inventory' => ['smtp' => ['enabled' => false]]])->save();
        MailStats::store($site, [['2026-11-01', 100, 9], ['2026-11-11', 40, 0], ['2026-11-17', 5, 1]]);
        MailStats::store($other, [['2026-11-17', 50, 50]]);

        $data = ReportData::build($customer, CarbonImmutable::parse('2026-10-18'), CarbonImmutable::parse('2026-11-17'));
        $mail = collect($data['sites'])->keyBy('name');
        $this->assertSame(['sent' => 45, 'failed' => 1, 'since' => null], $mail[$site->name]['mail'], 'week: 11 t/m 17 nov');
        $this->assertNull($mail[$other->name]['mail'], 'SMTP-module uit: geen cijfers');

        $customer->update(['report_mail_period' => 'quarter']);
        $data = ReportData::build($customer->fresh(), CarbonImmutable::parse('2026-10-18'), CarbonImmutable::parse('2026-11-17'));
        $this->assertSame(145, collect($data['sites'])->firstWhere('name', $site->name)['mail']['sent']);
        $this->assertSame('Afgelopen kwartaal', $data['mail_period']['label']);

        $this->actingAs($this->makeUser())->get("/klanten/{$customer->id}/rapport/bekijken?from=2026-10-18&to=2026-11-17")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Keuze opslaan via het klantformulier.
        $this->put("/klanten/{$customer->id}", [
            'name' => $customer->name, 'email' => $customer->email, 'visits_per_year' => 4, 'contract_start' => '2026-01-15',
            'report_sections' => ['mail'], 'report_mail_period' => 'month', 'sites' => [$site->id, $other->id],
        ])->assertRedirect();
        $this->assertSame('month', $customer->fresh()->report_mail_period);
        $this->put("/klanten/{$customer->id}", [
            'name' => $customer->name, 'email' => $customer->email, 'visits_per_year' => 4, 'contract_start' => '2026-01-15',
            'report_mail_period' => 'jaar',
        ])->assertSessionHasErrors('report_mail_period');
    }

    public function test_customer_details_can_be_edited_and_linked_from_the_site_page(): void
    {
        $site = $this->makePairedSite();
        $user = $this->makeUser();
        $this->actingAs($user)->get("/sites/{$site->site_id}")->assertOk()->assertSee('id="sd-klant"', false)->assertSee('hoort nog niet bij een klant');

        $customer = $this->customer();
        $this->post("/sites/{$site->site_id}/klant", ['customer_id' => $customer->id])->assertRedirect();
        $this->assertSame($customer->id, $site->fresh()->customer_id);
        $this->assertSame('Bakkerij De Korst', $site->fresh()->client_name);

        $this->get("/sites/{$site->site_id}")->assertOk()->assertSee('anna@korst.example');
        $this->put("/sites/{$site->site_id}/klant", ['name' => 'Bakkerij De Korst B.V.', 'email' => 'niet-geldig'])->assertSessionHasErrors('email');
        $this->put("/sites/{$site->site_id}/klant", [
            'name' => 'Bakkerij De Korst B.V.', 'contact_name' => 'Bert', 'email' => 'bert@korst.example', 'phone' => '0612345678', 'notes' => 'Belt liever',
        ])->assertRedirect();
        $customer->refresh();
        $this->assertSame(['Bakkerij De Korst B.V.', 'Bert', 'bert@korst.example', '0612345678', 'Belt liever'], [$customer->name, $customer->contact_name, $customer->email, $customer->phone, $customer->notes]);
        $this->assertSame('Bakkerij De Korst B.V.', $site->fresh()->client_name, 'naam ook bij de site');
        $this->assertSame(4, $customer->visits_per_year, 'contract blijft ongemoeid');

        $this->post("/sites/{$site->site_id}/klant", ['customer_id' => ''])->assertRedirect();
        $this->assertNull($site->fresh()->customer_id);
        $this->assertTrue(Customer::query()->whereKey($customer->id)->exists(), 'klant blijft bestaan');
    }

    public function test_viewer_sees_customer_details_but_cannot_change_them(): void
    {
        $site = $this->makePairedSite();
        $customer = $this->customer(['phone' => '010-1234567']);
        $site->forceFill(['customer_id' => $customer->id])->save();
        $viewer = $this->makeUser('viewer');

        $this->actingAs($viewer)->get("/sites/{$site->site_id}")->assertOk()->assertSee('010-1234567')->assertDontSee(route('sites.customer.update', $site));
        $this->put("/sites/{$site->site_id}/klant", ['name' => 'X', 'email' => 'x@x.nl'])->assertForbidden();
        $this->post("/sites/{$site->site_id}/klant", ['customer_id' => ''])->assertForbidden();
        $this->assertSame($customer->id, $site->fresh()->customer_id);
        $this->assertSame(0, SiteJob::query()->where('command', 'site.schedule')->count());
    }
}
