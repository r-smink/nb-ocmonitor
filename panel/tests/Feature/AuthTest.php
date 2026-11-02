<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Inloggen', false);
    }

    public function test_wrong_password_is_rejected_and_throttled(): void
    {
        $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'admin@nextbuzz.test', 'password' => 'fout'])->assertSessionHasErrors('email');
        }
        // Ook het juiste wachtwoord wordt nu geweigerd.
        $this->post('/login', ['email' => 'admin@nextbuzz.test', 'password' => 'een-lang-wachtwoord'])->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Te veel pogingen', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_password_alone_does_not_log_in(): void
    {
        $this->makeUser();
        $this->post('/login', ['email' => 'admin@nextbuzz.test', 'password' => 'een-lang-wachtwoord'])->assertRedirect('/login/2fa');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_with_totp_and_code_reuse_is_rejected(): void
    {
        $user = $this->makeUser();
        $code = (new Google2FA())->getCurrentOtp($user->totp_secret);

        $this->post('/login', ['email' => 'admin@nextbuzz.test', 'password' => 'een-lang-wachtwoord']);
        $this->post('/login/2fa', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/login/2fa', ['code' => $code])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk()->assertSee('Sites');

        // Uitloggen en dezelfde code opnieuw proberen: geweigerd.
        $this->post('/logout');
        $this->post('/login', ['email' => 'admin@nextbuzz.test', 'password' => 'een-lang-wachtwoord']);
        $this->post('/login/2fa', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_first_login_requires_two_factor_setup(): void
    {
        $user = $this->makeUser('beheerder', twoFactor: false);
        $this->post('/login', ['email' => 'beheerder@nextbuzz.test', 'password' => 'een-lang-wachtwoord'])->assertRedirect('/login/2fa/instellen');
        $this->get('/login/2fa')->assertRedirect('/login');

        $page = $this->get('/login/2fa/instellen')->assertOk()->assertSee('<svg', false);
        $secret = session('login.setup_secret');
        $this->assertNotNull($secret);
        $this->assertGuest();

        $this->post('/login/2fa/instellen', ['code' => (new Google2FA())->getCurrentOtp($secret)])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->hasTwoFactor());
        // Het secret staat versleuteld in de database.
        $this->assertNotSame($secret, \DB::table('users')->where('id', $user->id)->value('totp_secret'));
    }

    public function test_viewer_cannot_manage_sites_or_users(): void
    {
        $viewer = $this->makeUser('viewer');
        $this->actingAs($viewer)->get('/sites/nieuw')->assertForbidden();
        $this->actingAs($viewer)->post('/sites', ['name' => 'x', 'url' => 'https://x.nl'])->assertForbidden();
        $this->actingAs($viewer)->get('/gebruikers')->assertForbidden();
    }

    public function test_beheerder_cannot_manage_users(): void
    {
        $this->actingAs($this->makeUser('beheerder'))->get('/gebruikers')->assertForbidden();
    }

    public function test_bootstrap_admin_from_env(): void
    {
        config(['ocm.admin.email' => 'eerste@nextbuzz.test', 'ocm.admin.password' => 'heel-lang-wachtwoord']);
        $this->artisan('ocm:bootstrap-admin')->assertSuccessful();
        $user = User::query()->where('email', 'eerste@nextbuzz.test')->firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertFalse($user->hasTwoFactor());

        // Tweede keer zonder reset: niets verandert.
        config(['ocm.admin.password' => 'ander-lang-wachtwoord']);
        $this->artisan('ocm:bootstrap-admin')->assertSuccessful();
        $this->assertTrue(\Hash::check('heel-lang-wachtwoord', $user->fresh()->password));
    }

    public function test_security_headers(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy');
    }
}
