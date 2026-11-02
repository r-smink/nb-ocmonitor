<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Events;

use NextBuzz\OcMonitor\Client\Schema;

final class EventStore
{
    private const ACKED_RETENTION = 30 * DAY_IN_SECONDS;
    private const UNACKED_RETENTION = 180 * DAY_IN_SECONDS;

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>> de opgeslagen events in API-formaat (met seq)
     */
    public function insertMany(array $events): array
    {
        global $wpdb;
        $stored = [];
        foreach ($events as $event) {
            $row = [
                'created_at' => (int) $event['time'],
                'type' => substr((string) $event['type'], 0, 64),
                'object' => substr((string) $event['object'], 0, 191),
                'from_version' => self::nullableString($event['from'] ?? null, 191),
                'to_version' => self::nullableString($event['to'] ?? null, 191),
                'result' => substr((string) $event['result'], 0, 16),
                'source' => substr((string) $event['source'], 0, 16),
                'job_id' => self::nullableString($event['job_id'] ?? null, 64),
                'user_id' => (int) $event['user_id'],
                'user_login' => substr((string) $event['user_login'], 0, 60),
                'ip' => substr((string) $event['ip'], 0, 45),
                'user_agent' => substr((string) $event['user_agent'], 0, 255),
                'data' => $event['data'] === [] ? null : wp_json_encode($event['data']),
            ];
            if ($wpdb->insert(Schema::eventsTable(), $row) !== false) {
                $row['seq'] = (int) $wpdb->insert_id;
                $stored[] = self::toApi($row);
            }
        }

        return $stored;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function after(int $afterSeq, int $limit): array
    {
        global $wpdb;
        $table = Schema::eventsTable();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE seq > %d ORDER BY seq ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
            $afterSeq,
            $limit
        ), ARRAY_A);

        return array_map([self::class, 'toApi'], is_array($rows) ? $rows : []);
    }

    /** @return list<array<string, mixed>> */
    public function latest(int $limit): array
    {
        global $wpdb;
        $table = Schema::eventsTable();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY seq DESC LIMIT %d", $limit), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL

        return array_map([self::class, 'toApi'], is_array($rows) ? $rows : []);
    }

    public function ack(int $upToSeq): int
    {
        global $wpdb;
        $table = Schema::eventsTable();

        return (int) $wpdb->query($wpdb->prepare("UPDATE {$table} SET acked = 1 WHERE seq <= %d AND acked = 0", $upToSeq)); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    public function lastSeq(): int
    {
        global $wpdb;
        $table = Schema::eventsTable();

        return (int) $wpdb->get_var("SELECT MAX(seq) FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    public function cleanup(int $now): void
    {
        global $wpdb;
        $table = Schema::eventsTable();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE acked = 1 AND created_at < %d", $now - self::ACKED_RETENTION)); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %d", $now - self::UNACKED_RETENTION)); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function toApi(array $row): array
    {
        $data = isset($row['data']) && is_string($row['data']) ? json_decode($row['data'], true) : null;

        return [
            'seq' => (int) $row['seq'],
            'time' => (int) $row['created_at'],
            'type' => (string) $row['type'],
            'object' => (string) $row['object'],
            'from' => $row['from_version'] ?? null,
            'to' => $row['to_version'] ?? null,
            'result' => (string) $row['result'],
            'source' => (string) $row['source'],
            'job_id' => $row['job_id'] ?? null,
            'user_id' => (int) $row['user_id'],
            'user_login' => (string) $row['user_login'],
            'ip' => (string) $row['ip'],
            'user_agent' => (string) $row['user_agent'],
            'data' => is_array($data) ? $data : new \stdClass(),
        ];
    }

    /**
     * @param mixed $value
     */
    private static function nullableString($value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(is_scalar($value) ? (string) $value : (string) wp_json_encode($value), 0, $max);
    }
}
