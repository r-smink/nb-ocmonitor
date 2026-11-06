<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

/**
 * Vangt fatale PHP-fouten op (ook op pagina's die het panel zelf niet bezoekt) en bewaart ze
 * samengevat: per unieke fout één regel met aantal, eerste en laatste keer en de URL. Paden worden
 * relatief gemaakt; er gaan geen requestgegevens of bezoekersinformatie mee. Het panel haalt de
 * lijst op met errors.list zodra site.info meldt dat er iets nieuws is.
 */
final class ErrorCatcher
{
    public const OPTION = 'nb_ocm_php_errors';
    private const MAX = 50;
    private const FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

    public static function register(): void
    {
        register_shutdown_function([self::class, 'onShutdown']);
    }

    public static function onShutdown(): void
    {
        $error = error_get_last();
        if (!is_array($error) || !in_array((int) ($error['type'] ?? 0), self::FATAL, true)) {
            return;
        }
        try {
            self::store($error, isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', time());
        } catch (\Throwable $e) {
            // Nooit een tweede fout veroorzaken in een al kapotte request.
        }
    }

    /**
     * @param array{type?: int, message?: string, file?: string, line?: int} $error
     */
    public static function store(array $error, string $uri, int $now): void
    {
        if (!function_exists('get_option')) {
            return;
        }
        $entry = self::normalize($error, $uri, defined('ABSPATH') ? (string) ABSPATH : '');
        $all = get_option(self::OPTION);
        $all = is_array($all) ? $all : [];
        $items = is_array($all['items'] ?? null) ? $all['items'] : [];
        if (isset($items[$entry['hash']])) {
            $items[$entry['hash']]['count'] = (int) $items[$entry['hash']]['count'] + 1;
            $items[$entry['hash']]['last_seen'] = $now;
            $items[$entry['hash']]['url'] = $entry['url'];
        } else {
            $items[$entry['hash']] = $entry + ['count' => 1, 'first_seen' => $now, 'last_seen' => $now];
        }
        uasort($items, static function (array $a, array $b): int {
            return $b['last_seen'] <=> $a['last_seen'];
        });
        update_option(self::OPTION, ['items' => array_slice($items, 0, self::MAX, true), 'last_at' => $now], false);
    }

    /**
     * Pure functie (voor de tests): pad relatief, bericht ingekort, vaste sleutel per fout.
     *
     * @param array{type?: int, message?: string, file?: string, line?: int} $error
     * @return array{hash: string, type: string, message: string, file: string, line: int, url: string}
     */
    public static function normalize(array $error, string $uri, string $root): array
    {
        $strip = static function (string $s) use ($root): string {
            return $root !== '' ? str_replace([rtrim($root, '/\\') . '/', rtrim($root, '/\\') . '\\'], '', $s) : $s;
        };
        $message = $strip((string) ($error['message'] ?? ''));
        // Stack trace weglaten: die kan argumenten met gegevens bevatten.
        $message = trim((string) preg_replace('/\s*Stack trace:.*$/s', '', $message));
        $file = $strip((string) ($error['file'] ?? ''));
        $line = (int) ($error['line'] ?? 0);
        $types = [E_ERROR => 'Fatal error', E_PARSE => 'Parse error', E_CORE_ERROR => 'Core error', E_COMPILE_ERROR => 'Compile error', E_USER_ERROR => 'User error', E_RECOVERABLE_ERROR => 'Recoverable error'];
        $path = (string) parse_url($uri, PHP_URL_PATH);

        return [
            'hash' => substr(sha1($file . ':' . $line . ':' . preg_replace('/\d+/', 'N', $message)), 0, 16),
            'type' => $types[(int) ($error['type'] ?? 0)] ?? 'Error',
            'message' => function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500),
            'file' => substr($file, 0, 300),
            'line' => $line,
            'url' => substr($path !== '' ? $path : '/', 0, 300),
        ];
    }

    public static function lastAt(): int
    {
        $all = get_option(self::OPTION);

        return is_array($all) ? (int) ($all['last_at'] ?? 0) : 0;
    }

    /** @return array<string, mixed> errors.list */
    public static function list(): array
    {
        $all = get_option(self::OPTION);
        $items = is_array($all) && is_array($all['items'] ?? null) ? array_values($all['items']) : [];
        $log = null;
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $path = is_string(WP_DEBUG_LOG) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
            $log = ['path' => basename($path), 'size' => is_file($path) ? (int) filesize($path) : 0];
        }

        return ['items' => $items, 'last_at' => self::lastAt(), 'debug_log' => $log];
    }
}
