<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

/**
 * Levert de publieke sleutel (32 bytes) bij een key-id, of null als die onbekend of ingetrokken is.
 * Tijdens sleutelrotatie kan een resolver meerdere actieve sleutels kennen.
 */
interface KeyResolver
{
    public function publicKey(string $keyId): ?string;
}
