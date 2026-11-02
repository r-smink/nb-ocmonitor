<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

/**
 * Strikte base64url (RFC 4648 §5) zonder padding.
 *
 * Decoderen weigert alles wat niet canoniek is (padding, vreemde tekens, niet-nul
 * restbits), zodat één waarde maar één geldige representatie heeft.
 */
final class Base64Url
{
    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /**
     * @throws VerificationFailed
     */
    public static function decode(string $encoded): string
    {
        $length = strlen($encoded);
        if ($length % 4 === 1 || preg_match('/^[A-Za-z0-9_-]*$/D', $encoded) !== 1) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'invalid base64url');
        }

        $padded = strtr($encoded, '-_', '+/') . str_repeat('=', (4 - $length % 4) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false || self::encode($decoded) !== $encoded) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'non-canonical base64url');
        }

        return $decoded;
    }
}
