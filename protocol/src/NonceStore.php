<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

/**
 * Replay-bescherming. Implementaties moeten atomair zijn: twee gelijktijdige aanroepen met
 * dezelfde nonce mogen nooit allebei true teruggeven (WP: add_option, panel: Redis SET NX).
 */
interface NonceStore
{
    /**
     * Registreert de nonce tot $expiresAt (unix-tijd).
     *
     * @return bool true als de nonce nieuw was, false als hij al gebruikt is
     */
    public function useOnce(string $nonce, int $expiresAt): bool;
}
