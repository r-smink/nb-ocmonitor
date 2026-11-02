<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

/**
 * Alleen voor tests: niet persistent en niet gedeeld tussen processen.
 */
final class InMemoryNonceStore implements NonceStore
{
    /** @var array<string, int> */
    private array $used = [];

    public function useOnce(string $nonce, int $expiresAt): bool
    {
        if (isset($this->used[$nonce])) {
            return false;
        }
        $this->used[$nonce] = $expiresAt;

        return true;
    }
}
