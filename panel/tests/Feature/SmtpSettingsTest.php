<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Ocm\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SmtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_set_own_smtp_server(): void
    {
        $this->actingAs($this->makeUser());
        $this->get('/instellingen/meldingen')->assertOk()->assertSee('E-mail (SMTP-server)');

        $form = ['enabled' => 1, 'host' => 'smtp.nextbuzz.example', 'port' => 587, 'encryption' => 'starttls', 'username' => 'ocm', 'password' => 'geheim123', 'from_address' => 'ocm@nextbuzz.example', 'from_name' => 'Next Buzz'];
        $this->put('/instellingen/meldingen/smtp', ['host' => 'http://x', 'enabled' => 1, 'port' => 587, 'encryption' => 'starttls'])->assertSessionHasErrors(['host', 'from_address']);
        $this->put('/instellingen/meldingen/smtp', $form)->assertSessionHasNoErrors();

        $stored = Setting::get(MailSettings::KEY);
        $this->assertNotSame('geheim123', $stored['password'], 'versleuteld opgeslagen');
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.nextbuzz.example', config('mail.mailers.smtp.host'));
        $this->assertSame('geheim123', config('mail.mailers.smtp.password'));
        $this->assertTrue(config('mail.mailers.smtp.require_tls'));
        $this->assertSame('ocm@nextbuzz.example', config('mail.from.address'));
        $this->get('/instellingen/meldingen')->assertSee('eigen server: smtp.nextbuzz.example')->assertDontSee('geheim123');

        // Leeg wachtwoord = houden.
        $this->put('/instellingen/meldingen/smtp', ['password' => ''] + $form);
        $this->assertTrue(MailSettings::hasPassword());

        // Testmail (met de array-mailer, zodat er niets echt verstuurd wordt).
        Mail::fake();
        $this->post('/instellingen/meldingen/smtp/test')->assertSessionHas('status');
        Mail::assertSent(\App\Mail\AlertDigest::class);

        $this->put('/instellingen/meldingen/smtp', ['enabled' => 0] + $form)->assertSessionHasNoErrors();
        $this->assertFalse(MailSettings::enabled());
    }

    public function test_only_admins_can_change_smtp(): void
    {
        $this->actingAs($this->makeUser('viewer'))->put('/instellingen/meldingen/smtp', ['enabled' => 0, 'port' => 587, 'encryption' => 'none'])->assertForbidden();
    }
}
