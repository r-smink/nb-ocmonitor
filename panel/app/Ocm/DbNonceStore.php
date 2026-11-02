<?php

namespace App\Ocm;

use Illuminate\Support\Facades\DB;
use NextBuzz\OcMonitor\Protocol\NonceStore;

/** Atomair via de primary key: insertOrIgnore raakt 1 rij bij een nieuwe nonce, 0 bij een herhaling. */
final class DbNonceStore implements NonceStore
{
    public function useOnce(string $nonce, int $expiresAt): bool
    {
        try {
            return DB::table('protocol_nonces')->insertOrIgnore(['nonce' => $nonce, 'expires_at' => $expiresAt]) === 1;
        } catch (\Throwable $e) {
            return false; // fail closed
        }
    }

    public static function cleanup(): int
    {
        return DB::table('protocol_nonces')->where('expires_at', '<', time())->delete();
    }
}
