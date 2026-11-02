<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

/**
 * Houdt afgewezen verzoeken bij voor de beheerpagina. Om te voorkomen dat een aanvaller met
 * ongeldige verzoeken een schrijfactie per request afdwingt, wordt hoogstens één keer per
 * minuut geschreven. De tellingen zijn dus een ondergrens, geen exacte telling.
 */
final class OptionFailureLog implements FailureLog
{
    public const OPTION = 'nb_ocm_failures';
    private const WRITE_INTERVAL = 60;
    private const RECENT_MAX = 20;

    public function record(string $reason, string $ip, int $now): void
    {
        $log = self::read();
        if ($now - $log['last_write'] < self::WRITE_INTERVAL) {
            return;
        }

        $log['counts'][$reason] = ($log['counts'][$reason] ?? 0) + 1;
        array_unshift($log['recent'], [
            'time' => $now,
            'reason' => substr(preg_replace('/[^a-z_]/', '', $reason) ?? '', 0, 32),
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '',
        ]);
        $log['recent'] = array_slice($log['recent'], 0, self::RECENT_MAX);
        $log['last_write'] = $now;
        update_option(self::OPTION, $log, false);
    }

    /**
     * @return array{counts: array<string, int>, recent: list<array{time: int, reason: string, ip: string}>, last_write: int}
     */
    public static function read(): array
    {
        $log = get_option(self::OPTION);
        if (!is_array($log)) {
            $log = [];
        }

        return [
            'counts' => is_array($log['counts'] ?? null) ? $log['counts'] : [],
            'recent' => is_array($log['recent'] ?? null) ? $log['recent'] : [],
            'last_write' => (int) ($log['last_write'] ?? 0),
        ];
    }
}
