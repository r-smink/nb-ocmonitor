<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

use NextBuzz\OcMonitor\Protocol\NonceStore;

/**
 * Atomair dankzij de primary key: INSERT IGNORE raakt precies één rij bij een nieuwe nonce en
 * nul rijen bij een herhaling. Bij een DB-fout geeft hij false terug (fail closed).
 */
final class DbNonceStore implements NonceStore
{
    public function useOnce(string $nonce, int $expiresAt): bool
    {
        global $wpdb;
        $table = Schema::noncesTable();
        $affected = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (nonce, expires_at) VALUES (%s, %d)", // phpcs:ignore WordPress.DB.PreparedSQL
            $nonce,
            $expiresAt
        ));

        return $affected === 1;
    }

    public static function cleanup(int $now): void
    {
        global $wpdb;
        $table = Schema::noncesTable();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires_at < %d", $now)); // phpcs:ignore WordPress.DB.PreparedSQL
    }
}
