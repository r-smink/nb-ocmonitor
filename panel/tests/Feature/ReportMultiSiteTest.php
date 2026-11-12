<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Ocm\EventIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportMultiSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_for_customer_with_two_sites(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-04-15 15:00'));
        $customer = Customer::query()->create(['name' => 'Bakkerij De Korst', 'contact_name' => 'Anna', 'email' => 'anna@korst.example', 'visits_per_year' => 4, 'contract_start' => '2026-01-15', 'report_enabled' => true]);
        \App\Ocm\Reports\ReportDesign::save(['report_name' => 'domain']);
        \Illuminate\Support\Facades\Mail::fake();
        $a = $this->makePairedSite('bakkerij.example.nl');
        $a->forceFill(['site_id' => 'site_a', 'name' => 'Bakkerij', 'customer_id' => $customer->id, 'wp_version' => '6.8.3', 'php_version' => '8.3.10',
            'inventory' => ['core' => ['version' => '6.8.3'], 'plugins' => [['file' => 'akismet/akismet.php', 'name' => 'Akismet', 'version' => '5.1', 'active' => true]], 'themes' => []]])->save();
        $b = $this->makePairedSite('webshop.example.nl');
        $b->forceFill(['site_id' => 'site_b', 'name' => 'Webshop', 'customer_id' => $customer->id, 'wp_version' => '6.8.3', 'php_version' => '8.3.10',
            'inventory' => ['core' => ['version' => '6.8.3'], 'plugins' => [['file' => 'akismet/akismet.php', 'name' => 'Akismet', 'version' => '5.1', 'active' => true]], 'themes' => []]])->save();
        foreach ([$a, $b] as $site) {
            (new EventIngestor())->ingest($site, [
                ['seq' => 1, 'time' => time() - 86400, 'type' => 'plugin.update', 'object' => 'akismet/akismet.php', 'from' => '5.0', 'to' => '5.1', 'result' => 'success', 'source' => 'nb-ocm', 'user_login' => '', 'data' => []],
                ['seq' => 2, 'time' => time() - 7200, 'type' => 'login.failed_summary', 'object' => '', 'from' => null, 'to' => null, 'result' => 'success', 'source' => 'cron', 'user_login' => '', 'data' => ['count' => 80, 'locked' => 2, 'channels' => ['xmlrpc' => 70, 'wp-login' => 10]]],
                ['seq' => 3, 'time' => time() - 3600, 'type' => 'plugin.activated', 'object' => 'akismet/akismet.php', 'from' => null, 'to' => null, 'result' => 'success', 'source' => 'wp-admin', 'user_login' => 'anna', 'data' => []],
            ]);
        }

        // Zoals de import ze aanmaakt: nog niet gekoppeld, zonder inventaris.
        $c = new \App\Models\Site(['site_id' => 'site_c', 'name' => 'nieuw.example.nl', 'customer_id' => $customer->id, 'client_name' => $customer->name, 'url' => 'https://nieuw.example.nl', 'host' => 'nieuw.example.nl']);
        $c->scopes = \App\Models\Site::DEFAULT_SCOPES;
        $c->save();

        $this->actingAs($this->makeUser());
        $pdf = $this->get("/klanten/{$customer->id}/rapport/bekijken?from=2026-01-16&to=2026-04-15");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get("/klanten/{$customer->id}/rapport/bekijken?from=2026-01-16&to=2026-04-15&download=1")->assertOk();
        $this->post("/klanten/{$customer->id}/rapport")->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/klanten/{$customer->id}/onderhoud", ['due_on' => '2026-04-15', 'notes' => 'ok', 'send_report' => 1])->assertRedirect()->assertSessionHasNoErrors();
    }
}
