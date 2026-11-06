<?php

namespace Tests\Feature;

use App\Mail\MaintenanceReport;
use App\Models\Customer;
use App\Ocm\Reports\ReportDesign;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceMailTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        $customer = Customer::query()->create(['name' => 'Bakkerij De Korst BV', 'contact_name' => 'Anna', 'email' => 'anna@korst.example', 'visits_per_year' => 4, 'contract_start' => '2026-01-05']);
        $site = $this->makePairedSite('www.bakkerijdekorst.nl');
        $site->forceFill(['customer_id' => $customer->id])->save();

        return $customer;
    }

    public function test_mail_uses_domain_and_editable_template(): void
    {
        $customer = $this->customer();
        $from = CarbonImmutable::parse('2026-07-16');
        $to = CarbonImmutable::parse('2026-10-15');
        $this->assertSame('bakkerijdekorst.nl', $customer->domainLabel());

        $mail = new MaintenanceReport($customer, ReportDesign::get(), $from, $to, '%PDF-', 'x.pdf');
        $this->assertSame('Onderhoudsrapport bakkerijdekorst.nl – oktober 2026', $mail->envelope()->subject);
        $text = $mail->render();
        $this->assertStringContainsString('Beste Anna,', $text);
        $this->assertStringContainsString('onderhoudsrapport van bakkerijdekorst.nl over de periode 16-07-2026 t/m 15-10-2026', $text);

        $this->actingAs($this->makeUser());
        $this->get('/rapporten')->assertOk()->assertSee('E-mail bij het rapport')->assertSee('{domein}');
        $this->put('/rapporten', array_merge(ReportDesign::get(), [
            'report_name' => 'domain',
            'mail_subject' => "Onderhoud {domein}\r\n({klant})",
            'mail_body' => "Hoi {contactpersoon}, rapport van {maand} voor {domein}. Groet, {bedrijf}",
        ]))->assertRedirect();

        $design = ReportDesign::get();
        $this->assertSame('Onderhoud {domein} ({klant})', $design['mail_subject'], 'geen regeleinden in het onderwerp');
        $mail = new MaintenanceReport($customer, $design, $from, $to, '%PDF-', 'x.pdf');
        $this->assertSame('Onderhoud bakkerijdekorst.nl (Bakkerij De Korst BV)', $mail->envelope()->subject);
        $this->assertStringContainsString('Hoi Anna, rapport van oktober 2026 voor bakkerijdekorst.nl. Groet, Next Buzz', $mail->render());
        $this->assertSame('bakkerijdekorst.nl', $customer->reportName());

        // Leeg = standaardtekst terug.
        $this->put('/rapporten', array_merge(ReportDesign::get(), ['mail_subject' => '', 'mail_body' => '']));
        $this->assertSame(ReportDesign::DEFAULTS['mail_body'], ReportDesign::get()['mail_body']);
    }

    public function test_customers_and_maintenance_are_separate_pages(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00'));
        $this->customer();
        $this->actingAs($this->makeUser());

        $this->get('/klanten')->assertOk()->assertSee('Volgend onderhoud')->assertSee('05-10-2026')->assertSee('achterstallig')->assertDontSee('Onderhoudskalender');
        $this->get('/onderhoud')->assertOk()->assertSee('Onderhoudskalender')->assertSee('Eerstvolgend onderhoud');
        $this->get('/')->assertSee(route('maintenance.index'), false)->assertSee(route('customers.index'), false);
    }
}
