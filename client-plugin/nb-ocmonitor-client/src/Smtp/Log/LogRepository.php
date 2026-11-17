<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Log;

use NextBuzz\OcMonitor\Client\Schema;

/** De tabel {prefix}nb_ocm_mail_log. Alle SQL met $wpdb->prepare() of $wpdb->insert/update. */
final class LogRepository
{
    public const STALE_ERROR = 'Onbekend: geen resultaat van WordPress';
    private const BATCH = 1000;

    /** Kolommen voor de lijst (zonder headers en inhoud). */
    private const LIST_COLUMNS = 'id, created_at, status, connection, is_test, is_alert, to_email, subject, initiator, error, resent_of';

    public static function table(): string
    {
        return Schema::mailLogTable();
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): ?int
    {
        global $wpdb;
        $ok = $wpdb->insert(self::table(), $data, self::formats($data));

        return $ok ? (int) $wpdb->insert_id : null;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        global $wpdb;
        $wpdb->update(self::table(), $data, ['id' => $id], self::formats($data), ['%d']);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL

        return is_array($row) ? $row : null;
    }

    /**
     * @param array{status: string, s: string, from: string, to: string, paged: int} $f
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $f, ?int $start, ?int $end, int $limit, int $offset): array
    {
        global $wpdb;
        [$where, $args] = LogQuery::where($f, $start, $end);
        $t = self::table();
        $count = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
        $total = (int) $wpdb->get_var($args === [] ? $count : $wpdb->prepare($count, $args)); // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare("SELECT " . self::LIST_COLUMNS . " FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge($args, [$limit, $offset])), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /**
     * Rijen voor de export, in delen van 500 (nieuwste eerst).
     *
     * @param array{status: string, s: string, from: string, to: string, paged: int} $f
     * @return \Generator<int, array<string, mixed>>
     */
    public function each(array $f, ?int $start, ?int $end, int $max = 50000): \Generator
    {
        global $wpdb;
        [$where, $args] = LogQuery::where($f, $start, $end);
        $t = self::table();
        $before = PHP_INT_MAX;
        $done = 0;
        while ($done < $max) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE {$where} AND id < %d ORDER BY id DESC LIMIT %d", array_merge($args, [$before, 500])), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
            if (!is_array($rows) || $rows === []) {
                return;
            }
            foreach ($rows as $row) {
                $before = (int) $row['id'];
                $done++;
                yield $row;
            }
        }
    }

    /** @return array{sent: int, failed: int, backup: int, total: int} */
    public function stats(int $since): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS total, SUM(status = %s) AS sent, SUM(status = %s) AS failed, SUM(status = %s AND connection = %s) AS backup FROM ' . self::table() . ' WHERE created_at >= %d', // phpcs:ignore WordPress.DB.PreparedSQL
            'sent',
            'failed',
            'sent',
            'backup',
            $since
        ), ARRAY_A);

        return [
            'sent' => (int) ($row['sent'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'backup' => (int) ($row['backup'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
        ];
    }

    /**
     * Aantallen per dag in de tijdzone van de site. SQL telt per kwartier (UTC); PHP telt die op per
     * lokale dag (Chart::groupByDay), zodat ook dagen van 23 of 25 uur rond de zomertijd kloppen.
     * "sent" is inclusief backup, net als stats().
     *
     * @return list<array{date: string, sent: int, failed: int, backup: int}>
     */
    public function daily(int $since, int $until, \DateTimeZone $tz): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT FLOOR(created_at / 900) * 900 AS start, SUM(status = %s) AS sent, SUM(status = %s) AS failed, SUM(status = %s AND connection = %s) AS backup FROM ' . self::table() . ' WHERE created_at >= %d AND created_at < %d GROUP BY FLOOR(created_at / 900)', // phpcs:ignore WordPress.DB.PreparedSQL
            'sent',
            'failed',
            'sent',
            'backup',
            $since,
            $until
        ), ARRAY_A);

        return Chart::groupByDay(is_array($rows) ? $rows : [], $since, $until, $tz);
    }

    /** @param list<int> $ids */
    public function delete(array $ids): int
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        return (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . " WHERE id IN ({$placeholders})", $ids)); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    public function deleteAll(): int
    {
        global $wpdb;

        return (int) $wpdb->query('DELETE FROM ' . self::table()); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /** Rijen die na een uur nog op pending staan (bijv. pre_wp_mail of een fatal error) als mislukt markeren. */
    public function markStale(int $before, int $now): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET status = %s, error = %s, updated_at = %d WHERE status = %s AND created_at < %d', // phpcs:ignore WordPress.DB.PreparedSQL
            'failed',
            self::STALE_ERROR,
            $now,
            'pending',
            $before
        ));
    }

    /** Oude rijen in delen verwijderen, zodat een grote tabel niet lang op slot gaat. */
    public function deleteOlderThan(int $cutoff, int $maxBatches = 50): int
    {
        global $wpdb;
        $deleted = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $n = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE created_at < %d ORDER BY id LIMIT %d', $cutoff, self::BATCH)); // phpcs:ignore WordPress.DB.PreparedSQL
            $deleted += $n;
            if ($n < self::BATCH) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function formats(array $data): array
    {
        $ints = ['id', 'created_at', 'updated_at', 'is_test', 'is_alert', 'resent_of'];
        $out = [];
        foreach (array_keys($data) as $key) {
            $out[] = in_array($key, $ints, true) ? '%d' : '%s';
        }

        return $out;
    }
}
