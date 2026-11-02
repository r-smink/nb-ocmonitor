<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NextBuzz\OcMonitor\Protocol\KeyPair;
use NextBuzz\OcMonitor\Protocol\Pairing;
use Tests\TestCase;

class PairingTest extends TestCase
{
    use RefreshDatabase;

    private function proof(Site $site, ?KeyPair $keys = null, string $url = 'https://klant.example.nl'): array
    {
        return Pairing::proof($site->site_id, $keys ?? $this->siteKeys, $url, str_repeat("\x03", 32), time());
    }

    public function test_admin_creates_site_and_sees_pairing_code_that_the_plugin_can_parse(): void
    {
        $this->actingAs($this->makeUser())
            ->post('/sites', ['name' => 'Klant', 'url' => 'https://www.klant.nl/'])
            ->assertRedirect();
        $site = Site::query()->firstOrFail();
        $this->assertSame('www.klant.nl', $site->host);
        $this->assertSame('https://www.klant.nl', $site->url);

        $html = $this->get('/sites/' . $site->site_id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/nbocm1\.[A-Za-z0-9_-]+/', $html);
        preg_match('/nbocm1\.[A-Za-z0-9_-]+/', $html, $m);

        $parsed = Pairing::parseCode($m[0], time());
        $this->assertSame($site->site_id, $parsed['site_id']);
        $this->assertSame('https://ocm.test', $parsed['panel_url']);
        $this->assertSame($this->panelKeys->publicKey(), $parsed['panel_pk']);
    }

    public function test_http_site_urls_are_refused_by_default(): void
    {
        $this->actingAs($this->makeUser())
            ->post('/sites', ['name' => 'Klant', 'url' => 'http://www.klant.nl'])
            ->assertSessionHasErrors('url');
    }

    public function test_valid_proof_pairs_once(): void
    {
        $site = $this->pendingSite();
        $proof = $this->proof($site);

        $this->postJson('/api/pair', $proof)->assertOk()->assertJson(['ok' => true, 'site_id' => $site->site_id]);
        $site->refresh();
        $this->assertTrue($site->isPaired());
        $this->assertSame($this->siteKeys->keyId(), $site->key_id);
        $this->assertNull($site->pairing_secret);
        $this->assertSame(2, SiteJob::query()->count()); // site.info + site.inventory

        // Tweede keer (replay of tweede site met dezelfde code): geweigerd.
        $this->postJson('/api/pair', $proof)->assertNotFound();
    }

    public function test_proof_from_other_host_is_rejected_and_code_revoked(): void
    {
        $site = $this->pendingSite();
        $this->postJson('/api/pair', $this->proof($site, null, 'https://aanvaller.example'))->assertNotFound();
        $site->refresh();
        $this->assertFalse($site->isPaired());
        $this->assertNull($site->pairing_secret);
    }

    public function test_wrong_secret_is_rejected_without_revoking(): void
    {
        $site = $this->pendingSite();
        $proof = Pairing::proof($site->site_id, $this->siteKeys, 'https://klant.example.nl', random_bytes(32), time());
        $this->postJson('/api/pair', $proof)->assertNotFound();
        $this->assertNotNull($site->fresh()->pairing_secret);
    }

    public function test_expired_code_is_rejected(): void
    {
        $site = $this->pendingSite();
        $site->pairing_expires_at = now()->subMinute();
        $site->save();
        $this->postJson('/api/pair', $this->proof($site))->assertNotFound();
    }

    public function test_plugin_download_is_a_zip_for_logged_in_users_only(): void
    {
        $this->get('/download/nb-ocmonitor-client.zip')->assertRedirect('/login');

        $response = $this->actingAs($this->makeUser('viewer'))->get('/download/nb-ocmonitor-client.zip');
        $response->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file));
        $this->assertNotFalse($zip->locateName('nb-ocmonitor-client/nb-ocmonitor-client.php'));
        $this->assertNotFalse($zip->locateName('nb-ocmonitor-client/lib/protocol/Verifier.php'));
        $this->assertFalse($zip->locateName('nb-ocmonitor-client/tests/run.php'));
        $zip->close();
    }
}
