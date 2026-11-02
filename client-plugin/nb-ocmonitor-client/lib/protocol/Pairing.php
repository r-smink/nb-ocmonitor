<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

/**
 * Koppelen van een site aan het panel. Zie docs/protocol.md §2.
 *
 * 1. Panel maakt een koppelcode (panel-URL, site-id, publieke panel-sleutel, eenmalig secret).
 * 2. Site maakt een eigen sleutelpaar en stuurt een bewijs terug: HMAC met het secret
 *    (bewijst dat de code bekend is) plus een handtekening met de nieuwe site-sleutel
 *    (bewijst bezit van de private key).
 * 3. Panel bewaart de publieke site-sleutel en maakt het secret ongeldig.
 */
final class Pairing
{
    public const CODE_PREFIX = 'nbocm1.';
    public const CODE_LIFETIME = 900;
    public const PROOF_WINDOW = 300;

    public static function newSecret(): string
    {
        return random_bytes(32);
    }

    public static function createCode(string $panelUrl, string $siteId, KeyPair $panelKeys, string $secret, int $expiresAt): string
    {
        $code = [
            'v' => 1,
            'panel_url' => rtrim($panelUrl, '/'),
            'site_id' => $siteId,
            'panel_kid' => $panelKeys->keyId(),
            'panel_pk' => $panelKeys->publicKeyB64(),
            'secret' => Base64Url::encode($secret),
            'exp' => $expiresAt,
        ];

        return self::CODE_PREFIX . Base64Url::encode((string) json_encode($code, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{panel_url: string, site_id: string, panel_kid: string, panel_pk: string, secret: string, exp: int}
     *         panel_pk en secret als ruwe bytes
     * @throws VerificationFailed
     */
    public static function parseCode(string $code, int $now): array
    {
        $code = trim($code);
        if (strpos($code, self::CODE_PREFIX) !== 0 || strlen($code) > 2048) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'pairing code prefix');
        }
        $data = json_decode(Base64Url::decode(substr($code, strlen(self::CODE_PREFIX))), true, 2);
        if (!is_array($data) || ($data['v'] ?? null) !== 1) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'pairing code payload');
        }

        foreach (['panel_url', 'site_id', 'panel_kid', 'panel_pk', 'secret'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new VerificationFailed(VerificationFailed::MALFORMED, 'pairing ' . $field);
            }
        }
        if (!isset($data['exp']) || !is_int($data['exp'])) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'pairing exp');
        }
        if ($now > $data['exp']) {
            throw new VerificationFailed(VerificationFailed::EXPIRED);
        }
        if (preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9/_.-]*)?$#D', $data['panel_url']) !== 1) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'panel_url must be https');
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $data['site_id']) !== 1) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'pairing site_id');
        }

        $panelPk = Base64Url::decode($data['panel_pk']);
        $secret = Base64Url::decode($data['secret']);
        if (strlen($panelPk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !hash_equals(KeyPair::keyIdFor($panelPk), $data['panel_kid'])
            || strlen($secret) !== 32) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'pairing keys');
        }

        return [
            'panel_url' => $data['panel_url'],
            'site_id' => $data['site_id'],
            'panel_kid' => $data['panel_kid'],
            'panel_pk' => $panelPk,
            'secret' => $secret,
            'exp' => $data['exp'],
        ];
    }

    /**
     * @return array{site_id: string, site_pk: string, site_url: string, ts: int, hmac: string, sig: string}
     */
    public static function proof(string $siteId, KeyPair $siteKeys, string $siteUrl, string $secret, int $timestamp): array
    {
        $canonical = Canonical::pairing($siteId, $siteKeys->publicKeyB64(), $siteUrl, $timestamp);

        return [
            'site_id' => $siteId,
            'site_pk' => $siteKeys->publicKeyB64(),
            'site_url' => $siteUrl,
            'ts' => $timestamp,
            'hmac' => Base64Url::encode(hash_hmac('sha256', $canonical, $secret, true)),
            'sig' => Base64Url::encode($siteKeys->sign($canonical)),
        ];
    }

    /**
     * @param array<string, mixed> $proof
     * @return string de publieke sleutel (32 bytes) van de site
     * @throws VerificationFailed
     */
    public static function verifyProof(array $proof, string $expectedSiteId, string $secret, int $now): string
    {
        foreach (['site_id', 'site_pk', 'site_url', 'hmac', 'sig'] as $field) {
            if (!isset($proof[$field]) || !is_string($proof[$field])) {
                throw new VerificationFailed(VerificationFailed::MALFORMED, 'proof ' . $field);
            }
        }
        if (!isset($proof['ts']) || !is_int($proof['ts'])) {
            throw new VerificationFailed(VerificationFailed::MALFORMED, 'proof ts');
        }
        if (!hash_equals($expectedSiteId, $proof['site_id'])) {
            throw new VerificationFailed(VerificationFailed::SITE_MISMATCH);
        }
        if (abs($now - $proof['ts']) > self::PROOF_WINDOW) {
            throw new VerificationFailed(VerificationFailed::STALE_TIMESTAMP);
        }

        $canonical = Canonical::pairing($proof['site_id'], $proof['site_pk'], $proof['site_url'], $proof['ts']);
        $expectedHmac = hash_hmac('sha256', $canonical, $secret, true);
        if (!hash_equals($expectedHmac, Base64Url::decode($proof['hmac']))) {
            throw new VerificationFailed(VerificationFailed::BAD_SIGNATURE, 'hmac');
        }

        $sitePk = Base64Url::decode($proof['site_pk']);
        $signature = Base64Url::decode($proof['sig']);
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $canonical, $sitePk)) {
            throw new VerificationFailed(VerificationFailed::BAD_SIGNATURE, 'proof of possession');
        }

        return $sitePk;
    }
}
