<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\LicenseProduct;
use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\Signer\ResultProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LicensesTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $host, string $siteId, ?bool $elementorActive, string $plugin = '0.7.0'): Site
    {
        $site = $this->makePairedSite($host);
        $site->forceFill([
            'site_id' => $siteId, 'name' => ucfirst(strtok($host, '.')), 'plugin_version' => $plugin, 'last_seen_at' => now(),
            'inventory' => [
                'core' => ['version' => '6.9'],
                'plugins' => [
                    ['file' => 'elementor-pro/elementor-pro.php', 'name' => 'Elementor Pro', 'version' => '3.30', 'active' => true],
                    ['file' => 'formidable-pro/formidable-pro.php', 'name' => 'Formidable Pro', 'version' => '6.20', 'active' => true],
                ],
                'themes' => [],
                'licenses' => $elementorActive === null ? [] : ['elementor_pro' => ['active' => $elementorActive, 'detail' => null]],
            ],
        ])->save();

        return $site;
    }

    public function test_products_keys_status_and_activation(): void
    {
        $without = $this->site('zonder.example.nl', 'site_zonder', false);
        $with = $this->site('met.example.nl', 'site_met', true);
        $old = $this->site('oud.example.nl', 'site_oud', false, '0.6.0');
        $this->actingAs($this->makeUser());

        $this->post('/licenties/producten', ['preset' => 'elementor_pro'])->assertRedirect();
        $this->post('/licenties/producten', ['preset' => 'formidable_pro'])->assertRedirect();
        $this->post('/licenties/producten', ['name' => 'Eigen', 'plugin_file' => 'eigen/eigen.php', 'method' => 'option', 'option_name' => 'siteurl'])->assertSessionHasErrors('option_name');
        $elementor = LicenseProduct::query()->where('name', 'Elementor Pro')->sole();
        $this->assertSame('elementor_pro', $elementor->method);
        $this->assertSame('elementor_pro', $elementor->status_key);

        $this->post("/licenties/producten/{$elementor->id}/sleutels", ['label' => 'Agency', 'key' => 'abc;rm -rf'])->assertSessionHasErrors('key');
        $this->post("/licenties/producten/{$elementor->id}/sleutels", ['label' => 'Agency', 'key' => 'EP-1234567890abcdef', 'seats' => 1000])->assertSessionHasNoErrors();
        $license = License::query()->sole();
        $this->assertNotSame('EP-1234567890abcdef', DB::table('licenses')->value('key'), 'versleuteld in de database');
        $this->assertSame('EP-1234567890abcdef', $license->key);

        $this->get('/licenties')->assertOk()->assertSee('Elementor Pro')->assertSee('2 zonder actieve licentie', false)
            ->assertSee('EP-1' . str_repeat('•', 8) . 'cdef')->assertSee('Openen in WP-admin');
        $this->assertContains('Elementor Pro: licentie niet actief, dus geen updates', collect($without->fresh()->issues())->pluck('text')->all());

        // Bulk: alleen sites zonder actieve licentie en met plugin 0.7.0+.
        $this->post("/licenties/producten/{$elementor->id}/activeren", ['license' => $license->id])->assertSessionHas('status', fn ($s) => str_contains($s, '1 site(s)') && str_contains($s, 'Oud'));
        $job = SiteJob::query()->where('command', 'license.activate')->sole();
        $this->assertSame($without->id, $job->site_id);
        $this->assertSame(['adapter' => 'elementor_pro', 'key' => 'EP-1234567890abcdef'], $job->args);

        app(ResultProcessor::class)->process($job, $without, ['adapter' => 'elementor_pro', 'active' => true]);
        $this->assertSame(['adapter' => 'elementor_pro'], $job->fresh()->args, 'sleutel weg uit het archief');

        // Handmatige producten kun je niet vanuit het panel activeren.
        $formidable = LicenseProduct::query()->where('name', 'Formidable Pro')->sole();
        $formidable->licenses()->create(['label' => 'x', 'key' => 'FRM-12345678']);
        $this->post("/licenties/producten/{$formidable->id}/activeren/{$without->site_id}", ['license' => $formidable->licenses()->value('id')])->assertSessionHasErrors('license');
        // Een sleutel van een ander product mag niet.
        $this->post("/licenties/producten/{$elementor->id}/activeren/{$without->site_id}", ['license' => $formidable->licenses()->value('id')])->assertSessionHasErrors('license');
    }

    public function test_viewer_sees_no_keys(): void
    {
        $this->site('zonder.example.nl', 'site_zonder', false);
        $product = LicenseProduct::query()->create(LicenseProduct::PRESETS['elementor_pro']);
        $product->licenses()->create(['label' => 'Agency', 'key' => 'EP-1234567890abcdef']);
        $this->actingAs($this->makeUser('viewer'))->get('/licenties')->assertOk()->assertDontSee('EP-1234567890abcdef');
        $this->post("/licenties/producten/{$product->id}/activeren", ['license' => 1])->assertForbidden();
    }
}
