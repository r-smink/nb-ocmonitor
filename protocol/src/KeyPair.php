<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

/**
 * Ed25519-sleutelpaar. De secret key (64 bytes, libsodium-formaat) bevat ook de publieke sleutel.
 */
final class KeyPair
{
    private string $secretKey;
    private string $publicKey;

    private function __construct(string $secretKey)
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('Ed25519 secret key must be 64 bytes');
        }
        $this->secretKey = $secretKey;
        $this->publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
    }

    public static function generate(): self
    {
        return new self(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    }

    /** Alleen voor testvectoren: deterministisch sleutelpaar uit een seed van 32 bytes. */
    public static function fromSeed(string $seed): self
    {
        return new self(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed)));
    }

    public static function fromSecretKeyB64(string $encoded): self
    {
        return new self(Base64Url::decode($encoded));
    }

    public static function keyIdFor(string $publicKey): string
    {
        return 'k_' . substr(hash('sha256', $publicKey), 0, 16);
    }

    public function keyId(): string
    {
        return self::keyIdFor($this->publicKey);
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function publicKeyB64(): string
    {
        return Base64Url::encode($this->publicKey);
    }

    public function secretKeyB64(): string
    {
        return Base64Url::encode($this->secretKey);
    }

    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secretKey);
    }

    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId(), 'secretKey' => '***'];
    }
}
