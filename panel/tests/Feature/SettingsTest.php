<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Ocm\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_items_can_be_hidden_or_switched_off(): void
    {
        $site = $this->makePairedSite();
        $this->actingAs($this->makeUser());
        $this->get('/')->assertSee('WP-gebruikers')->assertSee('Instellingen');

        $this->put('/instellingen', ['menu' => ['wp-users' => 'off', 'versions' => 'hidden', 'sites' => 'off']])->assertRedirect();

        $page = $this->get('/')->assertOk();
        $page->assertDontSee('>WP-gebruikers<', false)->assertDontSee('>Versies<', false)->assertSee('>Sites<', false);
        $this->get('/wp-gebruikers')->assertNotFound();
        $this->post("/sites/{$site->site_id}/2fa", ['mode' => 'admins'])->assertNotFound();
        $this->get('/versies')->assertOk(); // verborgen = nog bereikbaar
        $this->get('/sites')->assertOk(); // vaste onderdelen kun je niet uitzetten
        $this->get('/sites/' . $site->site_id)->assertOk()->assertDontSee('<h3>WordPress-gebruikers</h3>', false);

        $this->put('/instellingen', ['menu' => ['wp-users' => 'visible']]);
        $this->get('/wp-gebruikers')->assertOk();
    }

    public function test_general_settings_override_env_and_secrets_are_encrypted(): void
    {
        config(['ocm.pagespeed.interval_days' => 7, 'ocm.visual.enabled' => true]);
        $this->actingAs($this->makeUser());
        $this->put('/instellingen', [
            'app_name' => 'NB Beheer', 'pagespeed_interval_days' => 14, 'visual_enabled' => '0',
            'wordfence_api_key' => 'wf-geheim-123', 'heartbeat_minutes' => 999,
        ])->assertSessionHasErrors('heartbeat_minutes');

        $this->put('/instellingen', ['app_name' => 'NB Beheer', 'pagespeed_interval_days' => 14, 'visual_enabled' => '0', 'wordfence_api_key' => 'wf-geheim-123'])->assertSessionHasNoErrors();
        $this->assertSame(14, config('ocm.pagespeed.interval_days'));
        $this->assertFalse(config('ocm.visual.enabled'));
        $this->assertSame('wf-geheim-123', config('ocm.vulnerabilities.api_key'));
        $this->assertStringNotContainsString('wf-geheim-123', json_encode(Setting::get(AppSettings::KEY)), 'versleuteld opgeslagen');

        $this->get('/instellingen')->assertOk()->assertSee('NB Beheer')->assertSee('opgeslagen, laat leeg om te houden')->assertDontSee('wf-geheim-123');
        $this->get('/')->assertSee('NB Beheer');

        // Leeg laten houdt de sleutel; "verwijderen" haalt hem weg.
        $this->put('/instellingen', ['app_name' => 'NB Beheer']);
        $this->assertTrue(AppSettings::hasSecret('wordfence_api_key'));
        $this->put('/instellingen', ['clear' => ['wordfence_api_key']]);
        $this->assertFalse(AppSettings::hasSecret('wordfence_api_key'));
    }

    public function test_only_admins_can_change_settings(): void
    {
        $this->actingAs($this->makeUser('viewer'));
        $this->get('/instellingen')->assertForbidden();
        $this->put('/instellingen', ['menu' => ['wp-users' => 'off']])->assertForbidden();
    }
}
