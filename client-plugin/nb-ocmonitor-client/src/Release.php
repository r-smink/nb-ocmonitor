<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

use NextBuzz\OcMonitor\Protocol\Base64Url;

/**
 * Controle van nieuwe versies van deze plugin. Een release-zip wordt ondertekend met een aparte
 * release-sleutel die NIET op de panel-server staat. Een gehackt panel kan dus geen eigen code
 * als "update" laten installeren: zonder geldige handtekening weigert de plugin de zip.
 */
final class Release
{
    /** Publieke release-sleutel (Ed25519, base64url). */
    public const PUBLIC_KEY = '9hx9ndTvLVpHTTspJZlv7aVFhCNOtn0voA8b4WZ42G8';

    public static function verify(string $zipBytes, string $signatureB64, string $publicKeyB64 = self::PUBLIC_KEY): bool
    {
        try {
            $signature = Base64Url::decode($signatureB64);
            $key = Base64Url::decode($publicKeyB64);
        } catch (\Throwable $e) {
            return false;
        }
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $zipBytes, $key);
    }
}
