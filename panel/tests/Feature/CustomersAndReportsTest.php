<?php

namespace Tests\Feature;

use App\Mail\MaintenanceReport;
use App\Models\Customer;
use App\Models\MaintenanceRun;
use App\Models\ReportAsset;
use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\EventIngestor;
use App\Ocm\Reports\ReportDesign;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Mpdf\Mpdf;
use Tests\TestCase;

class CustomersAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): Customer
    {
        return Customer::query()->create($attributes + [
            'name' => 'Bakkerij De Korst', 'contact_name' => 'Anna', 'email' => 'anna@korst.example',
            'visits_per_year' => 4, 'contract_start' => '2026-01-15', 'report_enabled' => true,
        ]);
    }

    private function png(int $w = 40, int $h = 40): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 226, 40, 48));
        $path = tempnam(sys_get_temp_dir(), 'png');
        imagepng($img, $path);

        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }

    public function test_due_dates_follow_the_contract_and_skip_weekends(): void
    {
        $c = $this->customer(['visits_per_year' => 4, 'contract_start' => '2026-01-15']);
        $dates = array_map(fn ($d) => $d->toDateString(), $c->dueDates(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')));
        $this->assertSame(['2026-01-15', '2026-04-15', '2026-07-15', '2026-10-15'], $dates);

        $monthly = $this->customer(['visits_per_year' => 12, 'contract_start' => '2026-01-31']);
        $dates = array_map(fn ($d) => $d->toDateString(), $monthly->dueDates(CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-05-31')));
        // 31 jan 2026 = zaterdag → 2 feb; 28 feb = zaterdag → 2 mrt; 31 mei = zondag → 1 juni (buiten de periode)
        $this->assertSame(['2026-02-02', '2026-03-02', '2026-03-31', '2026-04-30'], $dates);

        $none = $this->customer(['visits_per_year' => 0, 'contract_start' => null]);
        $this->assertSame([], $none->dueDates(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')));
        $this->assertNull($none->nextDue());
    }

    public function test_customer_crud_with_sites_and_calendar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00'));
        $site = $this->makePairedSite();
        $user = $this->makeUser();
        $this->actingAs($user)->post('/klanten', [
            'name' => 'Bakkerij De Korst', 'email' => 'anna@korst.example', 'report_cc' => 'piet@korst.example',
            'visits_per_year' => 12, 'contract_start' => '2026-10-05', 'report_enabled' => 1,
            'report_sections' => ['summary', 'updates', 'nonsense'], 'sites' => [$site->id],
        ])->assertSessionHasErrors('report_sections.2');

        $this->post('/klanten', [
            'name' => 'Bakkerij De Korst', 'email' => 'anna@korst.example', 'report_cc' => 'piet@korst.example',
            'visits_per_year' => 12, 'contract_start' => '2026-10-05', 'report_enabled' => 1,
            'report_sections' => ['summary', 'updates'], 'sites' => [$site->id],
        ])->assertRedirect();
        $customer = Customer::query()->firstOrFail();
        $this->assertSame($customer->id, $site->fresh()->customer_id);
        $this->assertSame(['anna@korst.example', 'piet@korst.example'], $customer->recipients());
        $this->assertSame(['summary', 'updates'], $customer->sections());

        // 5 okt (achterstallig), 5 nov (gepland), 5 dec = zaterdag → 7 dec.
        $this->get('/klanten')->assertOk()->assertSee('Bakkerij De Korst')->assertSee('november 2026')->assertSee('cal-item planned', false)
            // Kalender en eerstvolgend onderhoud tonen de websitenaam, de klantnaam staat in de tooltip en eronder.
            ->assertSee('cal-item planned" href="' . route('customers.show', $customer) . '" title="Klant (Bakkerij De Korst) · gepland">Klant</a>', false)
            ->assertSee('Bakkerij De Korst · 05-10-2026 · achterstallig');
        $this->get('/klanten?maand=2026-10')->assertOk()->assertSee('cal-item overdue', false);
        $this->get('/klanten/' . $customer->id)->assertOk()->assertSee('05-10-2026 · achterstallig', false)->assertSee('07-12-2026')->assertSee($site->name);
        $this->get('/sites/' . $site->site_id)->assertOk()->assertSee('Bakkerij De Korst');

        $this->delete('/klanten/' . $customer->id)->assertRedirect('/klanten');
        $this->assertNull($site->fresh()->customer_id);
    }

    public function test_completing_maintenance_sends_the_report(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-04-15 15:00'));
        $site = $this->makePairedSite();
        $customer = $this->customer(['report_cc' => 'piet@korst.example']);
        $site->update(['customer_id' => $customer->id]);
        (new EventIngestor())->ingest($site, [
            ['seq' => 1, 'time' => time() - 86400, 'type' => 'plugin.update', 'object' => 'akismet/akismet.php', 'from' => '5.0', 'to' => '5.1', 'result' => 'success', 'source' => 'nb-ocm', 'user_login' => '', 'data' => []],
            ['seq' => 2, 'time' => time() - 7200, 'type' => 'login.failed_summary', 'object' => '', 'from' => null, 'to' => null, 'result' => 'success', 'source' => 'cron', 'user_login' => '', 'data' => ['count' => 80, 'locked' => 2, 'channels' => ['xmlrpc' => 70, 'wp-login' => 10]]],
        ]);

        $this->actingAs($this->makeUser())->post("/klanten/{$customer->id}/onderhoud", [
            'due_on' => '2026-04-15', 'notes' => 'Back-up gecontroleerd.', 'send_report' => 1,
        ])->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'Rapport verstuurd naar anna@korst.example, piet@korst.example'));

        $run = MaintenanceRun::query()->firstOrFail();
        $this->assertNotNull($run->done_at);
        $this->assertNotNull($run->report_sent_at);
        Mail::assertSent(MaintenanceReport::class, function (MaintenanceReport $mail) {
            $attachments = $mail->attachments();

            return $mail->hasTo('anna@korst.example') && $mail->hasCc('piet@korst.example') && count($attachments) === 1;
        });
        $this->assertSame('2026-07-15', $customer->fresh()->nextDue(CarbonImmutable::parse('2026-04-15'))->toDateString());

        $pdf = $this->get("/klanten/{$customer->id}/rapport/bekijken?from=2026-01-16&to=2026-04-15");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_report_design_uploads_and_preview(): void
    {
        $this->actingAs($this->makeUser());
        $this->get('/rapporten')->assertOk()->assertSee('Opmaak')->assertSee('Achtergrond voorpagina');

        $this->put('/rapporten', ['company' => 'Next Buzz', 'title' => 'Onderhoudsrapport', 'color_primary' => '#00aa55', 'color_accent' => 'rood', 'font_body' => 'dejavuserif', 'font_size' => 99])->assertRedirect();
        $design = ReportDesign::get();
        $this->assertSame('#00AA55', $design['color_primary']);
        $this->assertSame(ReportDesign::DEFAULTS['color_accent'], $design['color_accent']);
        $this->assertSame('dejavuserif', $design['font_body']);
        $this->assertSame(14, $design['font_size']);

        $this->post('/rapporten/bestanden', ['kind' => 'logo', 'file' => $this->png()])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/rapporten/bestanden', ['kind' => 'background_page', 'file' => $this->png(248, 351)])->assertSessionHasNoErrors();

        // Een PDF als achtergrond voor de voorpagina (mPDF maakt een PDF die FPDI kan lezen).
        @mkdir(storage_path('app/reports/tmp'), 0770, true);
        $background = (new Mpdf(['tempDir' => storage_path('app/reports/tmp')]));
        $background->WriteHTML('<div style="background:#E22830;height:40mm">Achtergrond</div>');
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $background->Output('', 'S'));
        $this->post('/rapporten/bestanden', ['kind' => 'background_cover', 'file' => new UploadedFile($path, 'achtergrond.pdf', 'application/pdf', null, true)])->assertSessionHasNoErrors();
        $this->assertSame('application/pdf', ReportAsset::query()->where('kind', 'background_cover')->value('mime'));

        // Inhoud telt, niet de extensie.
        $fake = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($fake, '<?php echo "x";');
        $this->post('/rapporten/bestanden', ['kind' => 'background_page', 'file' => new UploadedFile($fake, 'x.png', 'image/png', null, true)])->assertSessionHasErrors('file');
        $this->post('/rapporten/bestanden', ['kind' => 'font_regular', 'file' => $this->png()])->assertSessionHasErrors('file');

        $preview = $this->get('/rapporten/voorbeeld');
        $preview->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $preview->getContent());

        $this->delete('/rapporten/bestanden/logo')->assertRedirect();
        $this->assertFalse(ReportAsset::query()->where('kind', 'logo')->exists());
    }

    public function test_start_updates_for_all_customer_sites(): void
    {
        $customer = $this->customer();
        $site = $this->makePairedSite();
        $site->plugin_version = '0.3.0';
        $site->customer_id = $customer->id;
        $site->inventory = [
            'core' => ['version' => '6.8', 'update' => ['version' => '6.9']],
            'plugins' => [['file' => 'a/a.php', 'slug' => 'a', 'name' => 'A', 'version' => '1', 'update' => ['version' => '2']]],
            'themes' => [['stylesheet' => 'astra', 'name' => 'Astra', 'version' => '4', 'update' => ['version' => '5']]],
            'translations_pending' => 2,
        ];
        $site->save();

        $this->actingAs($this->makeUser())->post("/klanten/{$customer->id}/updates")->assertRedirect();
        $this->assertSame(['update.plugin', 'update.theme', 'update.translations'], SiteJob::query()->orderBy('id')->pluck('command')->all());

        $this->post("/klanten/{$customer->id}/updates", ['core' => 1]);
        $this->assertSame(4, SiteJob::query()->count(), 'alleen WordPress komt erbij, de rest staat al in de wachtrij');
    }

    public function test_viewer_can_look_but_not_change(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->makeUser('viewer'));
        $this->get('/klanten/' . $customer->id)->assertOk()->assertDontSee('Updates starten');
        $this->post("/klanten/{$customer->id}/updates")->assertForbidden();
        $this->post('/klanten', ['name' => 'X', 'email' => 'x@x.example', 'visits_per_year' => 0])->assertForbidden();
        $this->put('/rapporten', ['company' => 'X'])->assertForbidden();
        $this->post("/klanten/{$customer->id}/rapport")->assertForbidden();
    }

    public function test_site_page_spinner_last_updated_and_login_security_tips(): void
    {
        $site = $this->makePairedSite();
        $site->plugin_version = '0.3.0';
        $site->last_seen_at = now();
        $site->pagespeed_requested_at = now();
        $site->inventory = [
            'core' => ['version' => '6.9'],
            'plugins' => [['file' => 'a/a.php', 'slug' => 'a', 'name' => 'Plugin A', 'version' => '1', 'active' => true, 'updated_at' => CarbonImmutable::parse('2026-10-01 12:00')->timestamp]],
            'themes' => [['stylesheet' => 'astra', 'name' => 'Astra', 'version' => '4', 'active' => true]],
            'environment' => ['https' => true, 'login_security' => ['lockout' => false, 'xmlrpc' => 'on', 'xmlrpc_mode' => 'on', 'hide_users' => true]],
        ];
        $site->save();
        (new EventIngestor())->ingest($site, [
            ['seq' => 1, 'time' => CarbonImmutable::parse('2026-10-20 09:00')->timestamp, 'type' => 'theme.update', 'object' => 'astra', 'from' => '3', 'to' => '4', 'result' => 'success', 'source' => 'wp-admin', 'user_login' => 'x', 'data' => []],
        ]);

        $this->actingAs($this->makeUser())->get('/sites/' . $site->site_id)->assertOk()
            ->assertSee('Aangevraagd')->assertDontSee('>Opnieuw meten</button>', false)
            ->assertDontSee('meting aangevraagd')
            ->assertSee('01-10-2026')
            ->assertSee('20-10-2026')
            ->assertSee('Blokkade na mislukte inlogpogingen staat uit')
            ->assertSee('Inloggen via XML-RPC staat aan');
    }
}
