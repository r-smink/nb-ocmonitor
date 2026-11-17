<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Alerts;

/** Laatste 50 afleverpogingen van meldingen, per kanaal (optie nb_ocm_smtp_alert_history). */
final class History
{
    public const OPTION = 'nb_ocm_smtp_alert_history';
    public const MAX = 50;

    /** @return list<array{time: int, type: string, channel: string, target: string, ok: bool, error: string}> nieuwste eerst */
    public static function all(): array
    {
        $list = get_option(self::OPTION);

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** @param list<array<string, mixed>> $entries */
    public static function add(array $entries): void
    {
        $list = self::all();
        foreach ($entries as $entry) {
            $list = self::push($list, $entry);
        }
        update_option(self::OPTION, $list, false);
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param array<string, mixed> $entry
     * @return list<array<string, mixed>>
     */
    public static function push(array $list, array $entry, int $max = self::MAX): array
    {
        array_unshift($list, [
            'time' => (int) ($entry['time'] ?? 0),
            'type' => (string) ($entry['type'] ?? ''),
            'channel' => (string) ($entry['channel'] ?? ''),
            'target' => mb_substr((string) ($entry['target'] ?? ''), 0, 200),
            'ok' => (bool) ($entry['ok'] ?? false),
            'error' => mb_substr((string) ($entry['error'] ?? ''), 0, 300),
        ]);

        return array_slice($list, 0, $max);
    }
}
