<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

/**
 * Bouwt de exacte strings die ondertekend worden. Zie docs/protocol.md §3.
 *
 * Elk berichttype heeft een eigen contextlabel (domain separation): een handtekening voor het ene
 * type is nooit geldig voor een ander type. Elk veld wordt vooraf gevalideerd, zodat geen veld
 * een newline kan bevatten en de string dus eenduidig te splitsen is.
 */
final class Canonical
{
    public const CTX_COMMAND = 'NBOCM-v1-cmd';    // panel -> site
    public const CTX_SITE = 'NBOCM-v1-site';      // site -> panel (events, heartbeat)
    public const CTX_RESPONSE = 'NBOCM-v1-resp';  // antwoord van site op een command
    public const CTX_LOGIN = 'NBOCM-v1-login';    // 1-click login-token
    public const CTX_PAIRING = 'NBOCM-v1-pair';   // koppelbewijs

    private const REQUEST_CONTEXTS = [self::CTX_COMMAND, self::CTX_SITE];

    public static function request(
        string $context,
        string $method,
        string $host,
        string $path,
        string $siteId,
        string $keyId,
        int $timestamp,
        string $nonce,
        string $body
    ): string {
        if (!in_array($context, self::REQUEST_CONTEXTS, true)) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'unknown request context');
        }

        return implode("\n", [
            $context,
            self::method($method),
            self::host($host),
            self::path($path),
            self::id($siteId, 'site_id'),
            self::id($keyId, 'key_id'),
            self::timestamp($timestamp),
            self::nonce($nonce),
            hash('sha256', $body),
        ]);
    }

    public static function response(
        string $siteId,
        string $keyId,
        int $timestamp,
        string $requestNonce,
        int $status,
        string $body
    ): string {
        if ($status < 100 || $status > 599) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'status');
        }

        return implode("\n", [
            self::CTX_RESPONSE,
            self::id($siteId, 'site_id'),
            self::id($keyId, 'key_id'),
            self::timestamp($timestamp),
            self::nonce($requestNonce),
            (string) $status,
            hash('sha256', $body),
        ]);
    }

    public static function loginToken(string $keyId, string $payloadB64): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,2000}$/D', $payloadB64) !== 1) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'payload');
        }

        return self::CTX_LOGIN . "\n" . self::id($keyId, 'key_id') . "\n" . $payloadB64;
    }

    public static function pairing(string $siteId, string $sitePublicKeyB64, string $siteUrl, int $timestamp): string
    {
        if (preg_match('#^https?://[^\s]+$#D', $siteUrl) !== 1) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'site_url');
        }
        if (strlen(Base64Url::decode($sitePublicKeyB64)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'site_public_key');
        }

        return implode("\n", [
            self::CTX_PAIRING,
            self::id($siteId, 'site_id'),
            $sitePublicKeyB64,
            $siteUrl,
            self::timestamp($timestamp),
        ]);
    }

    /** Lowercase, zonder standaardpoort. Gebruik altijd de geconfigureerde host, nooit de Host-header. */
    public static function host(string $host): string
    {
        $host = strtolower($host);
        $host = preg_replace('/:(443|80)$/D', '', $host);
        if (preg_match('/^[a-z0-9.-]{1,253}(:[0-9]{1,5})?$/D', (string) $host) !== 1) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'host');
        }

        return (string) $host;
    }

    private static function method(string $method): string
    {
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'method');
        }

        return $method;
    }

    /** Logisch endpoint (REST-route), bv. "/nb-ocm/v1/cmd". Geen querystring. */
    private static function path(string $path): string
    {
        if (preg_match('#^/[A-Za-z0-9/_.-]{1,200}$#D', $path) !== 1) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'path');
        }

        return $path;
    }

    private static function id(string $value, string $field): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value) !== 1) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, $field);
        }

        return $value;
    }

    private static function timestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'timestamp');
        }

        return (string) $timestamp;
    }

    private static function nonce(string $nonce): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{22,64}$/D', $nonce) !== 1) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'nonce');
        }

        return $nonce;
    }
}
