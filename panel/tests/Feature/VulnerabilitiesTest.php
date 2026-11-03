<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Vulnerability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VulnerabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function feed(): array
    {
        $range = fn ($from, $fi, $to, $ti) => ['from_version' => $from, 'from_inclusive' => $fi, 'to_version' => $to, 'to_inclusive' => $ti];

        return [
            'aaaa-1' => ['id' => 'aaaa-1', 'title' => 'Elementor <= 3.20.0 - Stored XSS', 'informational' => false, 'cve' => 'CVE-2026-0001',
                'cvss' => ['score' => 6.4, 'rating' => 'Medium'], 'published' => '2026-10-01T00:00:00+00:00',
                'software' => [['type' => 'plugin', 'slug' => 'elementor', 'name' => 'Elementor', 'affected_versions' => ['* - 3.20.0' => $range('*', true, '3.20.0', true)], 'patched' => true, 'patched_versions' => ['3.20.1']]]],
            'bbbb-2' => ['id' => 'bbbb-2', 'title' => 'Contact Form X < 2.0 - SQL Injection', 'informational' => false,
                'cvss' => ['score' => 9.8, 'rating' => 'Critical'],
                'software' => [['type' => 'plugin', 'slug' => 'contact-form-x', 'affected_versions' => ['* - 2.0' => $range('*', true, '2.0', false)], 'patched' => false, 'patched_versions' => []]]],
            'cccc-3' => ['id' => 'cccc-3', 'title' => 'Elementor 3.25 - oud lek', 'cvss' => ['score' => 8.0, 'rating' => 'High'],
                'software' => [['type' => 'plugin', 'slug' => 'elementor', 'affected_versions' => ['3.25.0 - 3.25.4' => $range('3.25.0', true, '3.25.4', true)], 'patched_versions' => ['3.25.5']]]],
            'dddd-4' => ['id' => 'dddd-4', 'title' => 'Plugin die we niet gebruiken', 'cvss' => ['score' => 10.0],
                'software' => [['type' => 'plugin', 'slug' => 'iets-anders', 'affected_versions' => ['*' => $range('*', true, '*', true)]]]],
        ];
    }

    public function test_feed_is_synced_matched_and_shown(): void
    {
        config(['ocm.vulnerabilities.api_key' => 'wf-key']);
        Http::fake(['www.wordfence.com/*' => Http::response(json_encode($this->feed()))]);
        $site = $this->makePairedSite();
        $site->forceFill(['last_seen_at' => now(), 'inventory' => [
            'core' => ['version' => '6.9'],
            'plugins' => [
                ['file' => 'elementor/elementor.php', 'slug' => 'elementor', 'name' => 'Elementor', 'version' => '3.19.2', 'active' => true],
                ['file' => 'contact-form-x/cfx.php', 'slug' => 'contact-form-x', 'name' => 'Contact Form X', 'version' => '1.9.9', 'active' => true],
            ],
            'themes' => [],
        ]])->save();

        $this->artisan('ocm:vulnerabilities')->assertSuccessful()->expectsOutputToContain('4 kwetsbaarheden in de feed, 3 relevant');
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer wf-key') && str_contains($r->url(), '/api/intelligence/v3/vulnerabilities/production'));
        $this->assertSame(3, Vulnerability::query()->count(), 'alleen wat op onze sites staat');

        $texts = collect($site->fresh()->issues());
        $this->assertTrue($texts->contains(fn ($i) => $i['level'] === 'crit' && $i['text'] === 'Kwetsbaarheid in Contact Form X 1.9.9 (CVSS 9.8): nog geen oplossing'));
        $this->assertTrue($texts->contains(fn ($i) => $i['level'] === 'warn' && $i['text'] === 'Kwetsbaarheid in Elementor 3.19.2 (CVSS 6.4): bijwerken naar 3.20.1'));
        $this->assertFalse($texts->contains(fn ($i) => str_contains($i['text'], 'oud lek')), '3.25-lek raakt 3.19.2 niet');

        $this->actingAs($this->makeUser())->get('/kwetsbaarheden')->assertOk()
            ->assertSee('Contact Form X &lt; 2.0 - SQL Injection', false)->assertSee('CVE-2026-0001')->assertSee('Wordfence Intelligence')
            ->assertSee('https://www.wordfence.com/threat-intel/vulnerabilities/id/bbbb-2', false);
        $this->get('/kwetsbaarheden?niveau=crit')->assertOk()->assertDontSee('Stored XSS');

        // Na een update van de plugin is de melding weg.
        $inv = $site->fresh()->inventory;
        $inv['plugins'][0]['version'] = '3.20.1';
        $site->forceFill(['inventory' => $inv])->save();
        $this->assertFalse(collect($site->fresh()->issues())->contains(fn ($i) => str_contains($i['text'], 'Elementor')));
    }

    public function test_version_ranges(): void
    {
        $this->assertTrue(Vulnerability::inRange('1.0', ['from_version' => '*', 'to_version' => '1.0', 'to_inclusive' => true]));
        $this->assertFalse(Vulnerability::inRange('1.0', ['from_version' => '*', 'to_version' => '1.0', 'to_inclusive' => false]));
        $this->assertFalse(Vulnerability::inRange('0.9', ['from_version' => '1.0', 'from_inclusive' => true, 'to_version' => '2.0', 'to_inclusive' => true]));
        $this->assertTrue(Vulnerability::inRange('5.0', ['from_version' => '*', 'to_version' => '*']));
    }

    public function test_failed_feed_keeps_existing_data(): void
    {
        config(['ocm.vulnerabilities.api_key' => 'wf-key']);
        Vulnerability::query()->create(['uuid' => 'x', 'type' => 'plugin', 'slug' => 'elementor', 'affected' => [], 'title' => 'bestaand']);
        Http::fake(['www.wordfence.com/*' => Http::response('', 429)]);
        $this->artisan('ocm:vulnerabilities')->assertFailed();
        $this->assertSame(1, Vulnerability::query()->count());
        $this->actingAs($this->makeUser())->get('/kwetsbaarheden')->assertSee('Laatste update van de feed mislukt');
    }
}
