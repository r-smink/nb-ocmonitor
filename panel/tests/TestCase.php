<?php

namespace Tests;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use NextBuzz\OcMonitor\Protocol\Base64Url;
use NextBuzz\OcMonitor\Protocol\KeyPair;
use PragmaRX\Google2FA\Google2FA;

abstract class TestCase extends BaseTestCase
{
    protected KeyPair $panelKeys;

    protected KeyPair $siteKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->panelKeys = KeyPair::fromSeed(str_repeat("\x01", 32));
        $this->siteKeys = KeyPair::fromSeed(str_repeat("\x02", 32));
        config([
            'app.url' => 'https://ocm.test',
            'ocm.panel_public_key' => $this->panelKeys->publicKeyB64(),
            'ocm.signing_key' => $this->panelKeys->secretKeyB64(),
            'ocm.client_plugin_path' => base_path('../client-plugin/nb-ocmonitor-client'),
            'ocm.allow_http_sites' => false,
        ]);
    }

    protected function makeUser(string $role = 'admin', bool $twoFactor = true): User
    {
        $user = User::query()->create([
            'name' => ucfirst($role),
            'email' => $role . '@nextbuzz.test',
            'password' => 'een-lang-wachtwoord',
            'role' => $role,
        ]);
        if ($twoFactor) {
            $user->totp_secret = (new Google2FA())->generateSecretKey(32);
            $user->totp_confirmed_at = now();
            $user->save();
        }

        return $user;
    }

    protected function makePairedSite(string $host = 'klant.example.nl'): Site
    {
        $site = new Site(['site_id' => 'site_test01', 'name' => 'Klant', 'url' => 'https://' . $host, 'host' => $host]);
        $site->scopes = Site::DEFAULT_SCOPES;
        $site->public_key = $this->siteKeys->publicKeyB64();
        $site->key_id = $this->siteKeys->keyId();
        $site->paired_at = now();
        $site->status = 'online';
        $site->save();

        return $site;
    }

    protected function pendingSite(string $host = 'klant.example.nl'): Site
    {
        $site = new Site(['site_id' => 'site_test02', 'name' => 'Nieuw', 'url' => 'https://' . $host, 'host' => $host]);
        $site->scopes = Site::DEFAULT_SCOPES;
        $site->pairing_secret = Base64Url::encode(str_repeat("\x03", 32));
        $site->pairing_expires_at = now()->addMinutes(15);
        $site->save();

        return $site;
    }
}
