<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

/**
 * 1-click admin-login-token. Formaat: "{kid}.{base64url(json claims)}.{base64url(sig)}".
 * Wordt door de browser via POST aangeboden, nooit in een URL. Zie docs/protocol.md §6.
 */
final class LoginToken
{
    public const AUDIENCE = 'login';
    public const DEFAULT_LIFETIME = 60;

    /**
     * @param string $siteId   site waarvoor het token geldt
     * @param string $host     geconfigureerde host van die site
     * @param string $wpUser   user_login van het gemapte WP-account
     * @param string $panelUser identiteit van de medewerker in het panel (voor de log)
     */
    public static function issue(
        KeyPair $keys,
        string $siteId,
        string $host,
        string $wpUser,
        string $panelUser,
        int $now,
        int $lifetime = self::DEFAULT_LIFETIME,
        ?string $nonce = null
    ): string {
        $claims = [
            'aud' => self::AUDIENCE,
            'site_id' => $siteId,
            'host' => Canonical::host($host),
            'wp_user' => $wpUser,
            'panel_user' => $panelUser,
            'iat' => $now,
            'exp' => $now + $lifetime,
            'nonce' => $nonce ?? Signer::newNonce(),
        ];
        self::assertClaimShape($claims);

        $keyId = $keys->keyId();
        $payloadB64 = Base64Url::encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = Base64Url::encode($keys->sign(Canonical::loginToken($keyId, $payloadB64)));

        return $keyId . '.' . $payloadB64 . '.' . $signature;
    }

    /**
     * @param array<string, mixed> $claims
     * @throws VerificationFailed
     */
    public static function assertClaimShape(array $claims): void
    {
        $patterns = [
            'aud' => '/^[a-z]{1,16}$/D',
            'site_id' => '/^[A-Za-z0-9_-]{1,64}$/D',
            'host' => '/^[a-z0-9.-]{1,253}(:[0-9]{1,5})?$/D',
            'wp_user' => '/^[A-Za-z0-9._@-]{1,60}$/D',
            'panel_user' => '/^[^\x00-\x1f]{1,120}$/uD',
            'nonce' => '/^[A-Za-z0-9_-]{22,64}$/D',
        ];
        foreach ($patterns as $field => $pattern) {
            if (!isset($claims[$field]) || !is_string($claims[$field]) || preg_match($pattern, $claims[$field]) !== 1) {
                throw new VerificationFailed(VerificationFailed::MALFORMED, 'claim ' . $field);
            }
        }
        foreach (['iat', 'exp'] as $field) {
            if (!isset($claims[$field]) || !is_int($claims[$field]) || $claims[$field] <= 0) {
                throw new VerificationFailed(VerificationFailed::MALFORMED, 'claim ' . $field);
            }
        }
    }
}
