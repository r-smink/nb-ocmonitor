<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

final class Signer
{
    private KeyPair $keys;

    public function __construct(KeyPair $keys)
    {
        $this->keys = $keys;
    }

    /**
     * Ondertekent een verzoek (panel->site met CTX_COMMAND, site->panel met CTX_SITE).
     *
     * @return array<string, string> headers om mee te sturen
     */
    public function signRequest(
        string $context,
        string $method,
        string $host,
        string $path,
        string $siteId,
        string $body,
        int $timestamp,
        ?string $nonce = null
    ): array {
        $nonce = $nonce ?? self::newNonce();
        $keyId = $this->keys->keyId();
        $canonical = Canonical::request($context, $method, $host, $path, $siteId, $keyId, $timestamp, $nonce, $body);

        return [
            Headers::SITE => $siteId,
            Headers::KEY => $keyId,
            Headers::TS => (string) $timestamp,
            Headers::NONCE => $nonce,
            Headers::SIG => Base64Url::encode($this->keys->sign($canonical)),
        ];
    }

    /**
     * Ondertekent het antwoord van een site, gebonden aan de nonce van het verzoek.
     *
     * @return array<string, string>
     */
    public function signResponse(string $siteId, string $requestNonce, int $status, string $body, int $timestamp): array
    {
        $keyId = $this->keys->keyId();
        $canonical = Canonical::response($siteId, $keyId, $timestamp, $requestNonce, $status, $body);

        return [
            Headers::KEY => $keyId,
            Headers::TS => (string) $timestamp,
            Headers::SIG => Base64Url::encode($this->keys->sign($canonical)),
        ];
    }

    public static function newNonce(): string
    {
        return Base64Url::encode(random_bytes(24));
    }
}
