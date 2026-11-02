<?php

namespace App\Ocm;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238). Een code is maar één keer bruikbaar: de laatst gebruikte tijdstap wordt opgeslagen.
 */
final class TwoFactor
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
        $this->google2fa->setWindow(1);
    }

    public function newSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function qrSvg(User $user, string $secret): string
    {
        $uri = $this->google2fa->getQRCodeUrl('NB OC Monitor', $user->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd())))->writeString($uri);

        // XML-declaratie weg, zodat de SVG inline in de pagina kan.
        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    /** Controleert de code tegen een (nog niet bevestigd) secret. */
    public function verifySecret(string $secret, string $code): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/D', $code) !== 1) {
            return null;
        }
        // Met oude tijdstap 0 geeft google2fa de gevonden tijdstap terug (met null alleen true).
        $timestep = $this->google2fa->verifyKeyNewer($secret, $code, 0);

        return is_int($timestep) ? $timestep : null;
    }

    /** Controleert de code van een gebruiker en weigert hergebruik van een eerder gebruikte code. */
    public function verifyUser(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if ($user->totp_secret === null || preg_match('/^\d{6}$/D', $code) !== 1) {
            return false;
        }
        $timestep = $this->google2fa->verifyKeyNewer($user->totp_secret, $code, (int) ($user->totp_last_timestep ?? 0));
        if (!is_int($timestep)) {
            return false;
        }
        $user->totp_last_timestep = $timestep;
        $user->save();

        return true;
    }
}
