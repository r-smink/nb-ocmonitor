<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

/**
 * Controleert inkomende verzoeken, antwoorden en login-tokens. Zie docs/protocol.md §4.
 *
 * Host, pad en site-id komen altijd uit de eigen configuratie van de ontvanger, nooit uit het
 * verzoek: een verzoek dat voor een andere site of ander endpoint ondertekend is, faalt daardoor
 * vanzelf op de handtekening. De nonce wordt pas verbruikt na een geldige handtekening, zodat
 * ongeldige verzoeken de nonce-opslag niet kunnen vullen.
 */
final class Verifier
{
    public const DEFAULT_WINDOW = 90;
    public const LOGIN_MAX_LIFETIME = 120;
    public const LOGIN_SKEW = 30;
    private const LOGIN_TOKEN_MAX_LENGTH = 2048;

    private KeyResolver $keys;
    private NonceStore $nonces;
    private int $window;

    public function __construct(KeyResolver $keys, NonceStore $nonces, int $window = self::DEFAULT_WINDOW)
    {
        $this->keys = $keys;
        $this->nonces = $nonces;
        $this->window = $window;
    }

    /**
     * @param array<string, string|string[]> $headers
     * @throws VerificationFailed
     */
    public function verifyRequest(
        string $context,
        string $method,
        string $expectedHost,
        string $path,
        string $expectedSiteId,
        array $headers,
        string $body,
        int $now
    ): VerifiedRequest {
        $h = Headers::normalize($headers);

        if (!hash_equals($expectedSiteId, $h[Headers::SITE] ?? '')) {
            throw new VerificationFailed(VerificationFailed::SITE_MISMATCH);
        }

        $keyId = $h[Headers::KEY] ?? '';
        $nonce = $h[Headers::NONCE] ?? '';
        $timestamp = $this->timestamp($h[Headers::TS] ?? '', $now);
        $publicKey = $this->resolve($keyId);

        $canonical = Canonical::request($context, $method, $expectedHost, $path, $expectedSiteId, $keyId, $timestamp, $nonce, $body);
        $this->checkSignature($h[Headers::SIG] ?? '', $canonical, $publicKey);

        if (!$this->nonces->useOnce($nonce, $timestamp + $this->window + 1)) {
            throw new VerificationFailed(VerificationFailed::REPLAY);
        }

        return new VerifiedRequest($keyId, $timestamp, $nonce);
    }

    /**
     * Controleert een antwoord van een site. Geen nonce-opslag nodig: het antwoord is gebonden
     * aan de (eenmalige) nonce van ons eigen verzoek.
     *
     * @param array<string, string|string[]> $headers
     * @return string key-id waarmee de site tekende
     * @throws VerificationFailed
     */
    public function verifyResponse(
        string $expectedSiteId,
        string $requestNonce,
        int $status,
        array $headers,
        string $body,
        int $now
    ): string {
        $h = Headers::normalize($headers);
        $keyId = $h[Headers::KEY] ?? '';
        $timestamp = $this->timestamp($h[Headers::TS] ?? '', $now);
        $publicKey = $this->resolve($keyId);

        $canonical = Canonical::response($expectedSiteId, $keyId, $timestamp, $requestNonce, $status, $body);
        $this->checkSignature($h[Headers::SIG] ?? '', $canonical, $publicKey);

        return $keyId;
    }

    /**
     * Controleert een 1-click login-token (formaat: kid.payload.sig) en verbruikt de nonce.
     *
     * @return array<string, mixed> de claims
     * @throws VerificationFailed
     */
    public function verifyLoginToken(string $token, string $expectedSiteId, string $expectedHost, int $now): array
    {
        if (strlen($token) > self::LOGIN_TOKEN_MAX_LENGTH || substr_count($token, '.') !== 2) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'token format');
        }
        [$keyId, $payloadB64, $signature] = explode('.', $token);

        $publicKey = $this->resolve($keyId);
        $this->checkSignature($signature, Canonical::loginToken($keyId, $payloadB64), $publicKey);

        $claims = json_decode(Base64Url::decode($payloadB64), true, 4);
        if (!is_array($claims)) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'payload');
        }
        LoginToken::assertClaimShape($claims);

        if ($claims['aud'] !== LoginToken::AUDIENCE) {
            throw new VerificationFailed(VerificationFailed::WRONG_AUDIENCE);
        }
        if (!hash_equals($expectedSiteId, $claims['site_id'])
            || !hash_equals(Canonical::host($expectedHost), $claims['host'])) {
            throw new VerificationFailed(VerificationFailed::SITE_MISMATCH);
        }

        $lifetime = $claims['exp'] - $claims['iat'];
        if ($lifetime < 1 || $lifetime > self::LOGIN_MAX_LIFETIME || $claims['iat'] > $now + self::LOGIN_SKEW) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'lifetime');
        }
        if ($now > $claims['exp'] + self::LOGIN_SKEW) {
            throw new VerificationFailed(VerificationFailed::EXPIRED);
        }

        if (!$this->nonces->useOnce($claims['nonce'], $claims['exp'] + self::LOGIN_SKEW + 1)) {
            throw new VerificationFailed(VerificationFailed::REPLAY);
        }

        return $claims;
    }

    private function timestamp(string $raw, int $now): int
    {
        if (preg_match('/^[1-9][0-9]{0,11}$/D', $raw) !== 1) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'timestamp');
        }
        $timestamp = (int) $raw;
        if (abs($now - $timestamp) > $this->window) {
            throw new VerificationFailed(VerificationFailed::STALE_TIMESTAMP);
        }

        return $timestamp;
    }

    private function resolve(string $keyId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $keyId) !== 1) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'key_id');
        }

        $publicKey = $this->keys->publicKey($keyId);
        if ($publicKey === null
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !hash_equals(KeyPair::keyIdFor($publicKey), $keyId)) {
            throw new VerificationFailed(VerificationFailed::UNKNOWN_KEY);
        }

        return $publicKey;
    }

    private function checkSignature(string $signatureB64, string $message, string $publicKey): void
    {
        $signature = Base64Url::decode($signatureB64);
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'signature length');
        }
        if (!sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new VerificationFailed(VerificationFailed::BAD_SIGNATURE);
        }
    }
}
