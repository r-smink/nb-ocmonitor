<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

/**
 * KeyResolver over een vaste lijst publieke sleutels (bv. uit plugin-opties: huidige panel-sleutel
 * plus de vorige tijdens een rotatie). Key-id's worden afgeleid, nooit uit de configuratie vertrouwd.
 */
final class StaticKeyResolver implements KeyResolver
{
    /** @var array<string, string> */
    private array $keys = [];

    /**
     * @param string[] $publicKeys ruwe publieke sleutels (32 bytes)
     */
    public function __construct(array $publicKeys)
    {
        foreach ($publicKeys as $publicKey) {
            $this->keys[KeyPair::keyIdFor($publicKey)] = $publicKey;
        }
    }

    public function publicKey(string $keyId): ?string
    {
        return $this->keys[$keyId] ?? null;
    }
}
