<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelLoginScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $version = '0.11.0', bool $panelLogin = true): Site
    {
        $site = $this->makePairedSite();
        $site->forceFill([
            'plugin_version' => $version, 'last_seen_at' => now(),
            'inventory' => ['core' => ['version' => '6.9'], 'plugins' => [], 'themes' => [], 'environment' => ['panel_login' => $panelLogin]],
            'wp_users' => ['users' => [['id' => 7, 'login' => 'beheer', 'email' => 'b@klant.example', 'name' => 'Beheer', 'roles' => ['administrator'], 'locked' => false, 'two_factor' => null, 'sessions' => 0, 'last_login' => null, 'registered' => null]], 'two_factor_mode' => 'off'],
        ])->save();

        return $site;
    }

    public function test_admin_logs_in_with_a_one_time_link(): void
    {
        $site = $this->site();
        $admin = $this->makeUser();
        $this->actingAs($admin);
        $this->get('/sites/' . $site->site_id)->assertOk()->assertSee('Inloggen in WP-admin als beheer', false);

        $this->post("/sites/{$site->site_id}/inloggen", ['user_id' => 99])->assertNotFound();
        $wait = $this->post("/sites/{$site->site_id}/inloggen", ['user_id' => 7]);
        $job = SiteJob::query()->where('command', 'auth.login_link')->sole();
        $wait->assertRedirect(route('sites.login.wait', [$site, $job]));
        $this->assertSame(['user_id' => 7, 'panel_user' => $admin->name], $job->args);

        $this->get(route('sites.login.wait', [$site, $job]))->assertOk()->assertSee('eenmalige inloglink');

        $url = 'https://klant.example.nl/wp-login.php?action=nb_ocm_sso&token=' . str_repeat('a', 48);
        $job->update(['status' => 'done', 'result' => ['url' => $url, 'expires' => time() + 60, 'user' => 'beheer']]);
        $this->get(route('sites.login.wait', [$site, $job]))->assertRedirect($url);
        $this->assertArrayNotHasKey('url', $job->fresh()->result, 'link na gebruik uit het archief');
        $this->get(route('sites.login.wait', [$site, $job]))->assertOk()->assertSee('al gebruikt');

        // Een link naar een andere host volgt het panel niet.
        $evil = SiteJob::query()->create(['job_id' => 'job_evil', 'site_id' => $site->id, 'command' => 'auth.login_link', 'args' => [], 'status' => 'done',
            'requested_by' => $admin->id, 'available_at' => now(), 'result' => ['url' => 'https://phish.example/x', 'user' => 'beheer']]);
        $this->get(route('sites.login.wait', [$site, $evil]))->assertOk()->assertDontSee('phish.example');
    }

    public function test_only_admins_and_only_when_the_site_allows_it(): void
    {
        $site = $this->site();
        $this->actingAs($this->makeUser('beheerder'));
        $this->post("/sites/{$site->site_id}/inloggen", ['user_id' => 7])->assertForbidden();
        $this->get('/sites/' . $site->site_id)->assertOk()->assertDontSee('Inloggen in WP-admin als', false);
    }

    public function test_blocked_on_old_plugin_or_when_switched_off_on_the_site(): void
    {
        $this->actingAs($this->makeUser());
        $site = $this->site('0.10.0');
        $this->post("/sites/{$site->site_id}/inloggen", ['user_id' => 7])->assertSessionHasErrors('login');
        $site->forceFill(['plugin_version' => '0.11.0', 'inventory' => ['environment' => ['panel_login' => false]]])->save();
        $this->post("/sites/{$site->site_id}/inloggen", ['user_id' => 7])->assertSessionHasErrors('login');
        $this->assertSame(0, SiteJob::query()->where('command', 'auth.login_link')->count());
    }

    public function test_next_maintenance_is_sent_to_the_dashboard_widget(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00'));
        $site = $this->site();
        $customer = Customer::query()->create(['name' => 'Bakkerij', 'email' => 'a@bakkerij.example', 'visits_per_year' => 4, 'contract_start' => '2026-08-15']);
        $this->actingAs($this->makeUser());
        $this->put("/klanten/{$customer->id}", ['name' => 'Bakkerij', 'email' => 'a@bakkerij.example', 'visits_per_year' => 4, 'contract_start' => '2026-08-15', 'sites' => [$site->id]])->assertRedirect();

        $job = SiteJob::query()->where('command', 'site.schedule')->latest('id')->firstOrFail();
        $this->assertSame('2026-11-16', $job->args['next']); // 15 nov = zaterdag → maandag 16
        $this->assertSame('4x per jaar', $job->args['contract']);
        $this->assertNull($job->args['last']);

        $this->put('/instellingen', ['widget_contact' => 'Vragen? Mail support@nextbuzz.nl']);
        SiteJob::query()->where('command', 'site.schedule')->update(['status' => 'done']);
        $this->post("/klanten/{$customer->id}/onderhoud", ['due_on' => '2026-11-16']);
        $job = SiteJob::query()->where('command', 'site.schedule')->where('status', 'pending')->sole();
        $this->assertSame('2026-11-16', $job->args['last']);
        $this->assertSame('2027-02-15', $job->args['next']);
        $this->assertSame('Vragen? Mail support@nextbuzz.nl', $job->args['contact']);
    }
}
