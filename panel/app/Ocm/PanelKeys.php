<?php

namespace App\Ocm;

use NextBuzz\OcMonitor\Protocol\Base64Url;
use NextBuzz\OcMonitor\Protocol\KeyPair;
use RuntimeException;

/**
 * De web-app kent alleen de publieke panel-sleutel. De private key bestaat alleen in de signer-container.
 */
final class PanelKeys
{
    public static function publicKey(): string
    {
        $encoded = (string) config('ocm.panel_public_key');
        if ($encoded === '') {
            throw new RuntimeException('OCM_PANEL_PUBLIC_KEY ontbreekt. Draai: php artisan ocm:keygen');
        }
        $raw = Base64Url::decode($encoded);
        if (strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('OCM_PANEL_PUBLIC_KEY is geen geldige Ed25519-sleutel.');
        }

        return $raw;
    }

    public static function keyId(): string
    {
        return KeyPair::keyIdFor(self::publicKey());
    }

    /** Alleen bruikbaar in de signer. Controleert dat de private key bij de publieke sleutel hoort. */
    public static function signingKeys(): KeyPair
    {
        $encoded = (string) config('ocm.signing_key');
        if ($encoded === '') {
            throw new RuntimeException('OCM_SIGNING_KEY ontbreekt (hoort alleen in signer.env).');
        }
        $keys = KeyPair::fromSecretKeyB64($encoded);
        if (!hash_equals(self::publicKey(), $keys->publicKey())) {
            throw new RuntimeException('OCM_SIGNING_KEY hoort niet bij OCM_PANEL_PUBLIC_KEY.');
        }

        return $keys;
    }

    /** Publieke panel-host en basispad uit APP_URL, zoals sites die ondertekenen. */
    public static function panelHost(): string
    {
        $parts = parse_url((string) config('app.url'));

        return ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public static function panelBasePath(): string
    {
        return rtrim((string) (parse_url((string) config('app.url'), PHP_URL_PATH) ?? ''), '/');
    }
}
