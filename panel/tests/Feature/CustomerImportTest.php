<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_then_import_customers_and_sites(): void
    {
        $existing = Customer::query()->create(['name' => 'Fietsenmaker Jansen', 'email' => '', 'visits_per_year' => 0]);
        $paired = $this->makePairedSite('www.jansenfietsen.nl');
        $paired->forceFill(['site_id' => 'site_test03'])->save();
        $other = Customer::query()->create(['name' => 'Ander', 'email' => 'a@b.nl', 'visits_per_year' => 0]);
        $taken = $this->makePairedSite('bezet.nl');
        $taken->forceFill(['site_id' => 'site_test02', 'customer_id' => $other->id])->save();

        $csv = "\u{FEFF}Klant;Contactpersoon;E-mail;Telefoon;CC;Website;Onderhoud;Startdatum;Notities\n"
            . "Bakkerij De Korst BV;Anna;anna@dekorst.nl;06-1;x@y.nl, fout;bakkerijdekorst.nl https://www.dekorst-webshop.nl/;4;;\n"
            . "bakkerij de korst bv;;;;;bakkerijdekorst.nl;;;\n"
            . "Fietsenmaker Jansen;Piet;piet@jansen.nl;;;jansenfietsen.nl, bezet.nl;2x per jaar;15-01-2027;\n"
            . ";;;;;los.nl;;;\n"
            . "Kapper;;geen-mail;;;ftp://kapper.nl;wekelijks;31-02-2027;\n";

        $this->actingAs($this->makeUser());
        $this->get('/klanten')->assertSee(route('customers.import'), false);
        $this->get('/klanten/importeren')->assertOk()->assertSee('Voorbeeldbestand');
        $this->get('/klanten/importeren/voorbeeld.csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $file = UploadedFile::fake()->createWithContent('klanten.csv', $csv);
        $this->post('/klanten/importeren', ['file' => $file])->assertOk()
            ->assertSee('2 nieuwe klant(en)')->assertSee('1 bestaande aanvullen')->assertSee('2 nieuwe site(s)')
            ->assertSee('1 bestaande site(s) koppelen')->assertSee('1 site(s) overgeslagen')
            ->assertSee('geen klantnaam')->assertSee('ongeldig CC-adres')->assertSee('niet herkend');
        $this->assertSame(2, Customer::query()->count(), 'voorbeeld slaat niets op');

        $this->post('/klanten/importeren/opslaan', ['data' => $csv])->assertRedirect('/klanten');

        $korst = Customer::query()->where('name', 'Bakkerij De Korst BV')->firstOrFail();
        $this->assertSame(['anna@dekorst.nl', 'Anna', 4, 'x@y.nl'], [$korst->email, $korst->contact_name, $korst->visits_per_year, $korst->report_cc]);
        $this->assertNull($korst->contract_start);
        $this->assertSame(['bakkerijdekorst.nl', 'www.dekorst-webshop.nl'], $korst->sites->pluck('host')->sort()->values()->all());
        $site = $korst->sites->firstWhere('host', 'www.dekorst-webshop.nl');
        $this->assertSame('https://www.dekorst-webshop.nl', $site->url);
        $this->assertFalse($site->isPaired());
        $this->assertNull($site->pairing_secret, 'koppelcode pas later');

        $existing->refresh();
        $this->assertSame(['piet@jansen.nl', 2, '2027-01-15'], [$existing->email, $existing->visits_per_year, $existing->contract_start->toDateString()]);
        $this->assertSame($existing->id, $paired->fresh()->customer_id, 'bestaande site zonder klant gekoppeld');
        $this->assertSame($other->id, $taken->fresh()->customer_id, 'site van andere klant blijft staan');

        $kapper = Customer::query()->where('name', 'Kapper')->firstOrFail();
        $this->assertSame(['', 0, 0], [$kapper->email, $kapper->visits_per_year, $kapper->sites()->count()]);
        $this->assertSame(4, Customer::query()->count());

        // Nogmaals importeren maakt niets dubbel en overschrijft niets.
        $korst->update(['contact_name' => 'Anna de Vries']);
        $this->post('/klanten/importeren/opslaan', ['data' => $csv]);
        $this->assertSame(4, Customer::query()->count());
        $this->assertSame(4, Site::query()->count());
        $this->assertSame('Anna de Vries', $korst->fresh()->contact_name);

        $this->get(route('sites.show', $site))->assertOk()->assertSee('nog geen koppelcode');
    }

    public function test_pasted_excel_rows_with_tabs_and_missing_header(): void
    {
        $this->actingAs($this->makeUser());
        $this->post('/klanten/importeren', ['data' => "bakkerij.nl\tAnna"])->assertOk()->assertSee('kolomnamen');
        $this->post('/klanten/importeren', ['data' => "Bedrijfsnaam\tWebsites\nKorst\tkorst.nl\n"])->assertOk()->assertSee('korst.nl')->assertSee('1 nieuwe klant(en)');
    }

    public function test_viewer_cannot_import(): void
    {
        $this->actingAs($this->makeUser('viewer'));
        $this->get('/klanten/importeren')->assertForbidden();
        $this->post('/klanten/importeren/opslaan', ['data' => "Klant\nX\n"])->assertForbidden();
    }
}
