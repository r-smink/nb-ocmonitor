<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Log;

/**
 * Wie verstuurde de mail? Zoekt in de backtrace de aanroep van wp_mail() en koppelt het bestand
 * aan een plugin, thema of WordPress zelf.
 */
final class Initiator
{
    public const CORE = 'WP Core';
    public const UNKNOWN = 'onbekend';
    private const CACHE = 'nb_ocm_smtp_log_plugins';

    /** @var array<string, string>|null map map/bestand => pluginnaam, per verzoek */
    private static ?array $plugins = null;
    /** @var array<string, string> */
    private static array $themes = [];

    /**
     * @return array{type: string, name: string, file: string}
     */
    public static function detect(): array
    {
        $frame = self::callerFrame(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)); // phpcs:ignore PHPCompatibility
        if ($frame === null) {
            return ['type' => 'unknown', 'name' => self::UNKNOWN, 'file' => ''];
        }
        $dirs = [
            'plugins' => defined('WP_PLUGIN_DIR') ? (string) WP_PLUGIN_DIR : '',
            'mu' => defined('WPMU_PLUGIN_DIR') ? (string) WPMU_PLUGIN_DIR : '',
            'themes' => function_exists('get_theme_root') ? (string) get_theme_root() : '',
            'core' => defined('ABSPATH') ? (string) ABSPATH : '',
        ];
        $result = self::classify($frame['file'], $dirs, self::pluginNames());
        if ($result['type'] === 'theme' && function_exists('wp_get_theme')) {
            $slug = $result['name'];
            if (!isset(self::$themes[$slug])) {
                $name = (string) wp_get_theme($slug)->get('Name');
                self::$themes[$slug] = $name !== '' ? $name : $slug;
            }
            $result['name'] = self::$themes[$slug];
        }
        $result['file'] = self::relative($frame['file'], $dirs['core']) . ':' . $frame['line'];

        return $result;
    }

    /**
     * Het frame van de wp_mail()-aanroep: file en line daarvan zijn de plek die wp_mail aanriep.
     *
     * @param list<array<string, mixed>> $trace
     * @return array{file: string, line: int}|null
     */
    public static function callerFrame(array $trace): ?array
    {
        foreach ($trace as $frame) {
            if (($frame['function'] ?? '') === 'wp_mail' && !isset($frame['class']) && isset($frame['file'])) {
                return ['file' => (string) $frame['file'], 'line' => (int) ($frame['line'] ?? 0)];
            }
        }

        return null;
    }

    /**
     * Bestand => bron. Voor een thema is name de mapnaam (detect() zoekt de echte naam op).
     *
     * @param array{plugins: string, mu: string, themes: string, core: string} $dirs
     * @param array<string, string> $pluginNames map (of los bestand) => naam
     * @return array{type: string, name: string, file: string}
     */
    public static function classify(string $file, array $dirs, array $pluginNames): array
    {
        $file = self::normalize($file);
        foreach (['mu' => 'mu-plugin', 'plugins' => 'plugin', 'themes' => 'theme'] as $key => $type) {
            $rest = self::under($file, $dirs[$key]);
            if ($rest === null || $rest === '') {
                continue;
            }
            $slug = explode('/', $rest)[0];
            if ($type === 'theme') {
                return ['type' => 'theme', 'name' => $slug, 'file' => $file];
            }
            if ($type === 'mu-plugin') {
                return ['type' => 'mu-plugin', 'name' => $slug . ' (mu-plugin)', 'file' => $file];
            }

            return ['type' => 'plugin', 'name' => $pluginNames[$slug] ?? $slug, 'file' => $file];
        }
        $rest = self::under($file, $dirs['core']);
        if ($rest !== null && (strpos($rest, 'wp-includes/') === 0 || strpos($rest, 'wp-admin/') === 0 || strpos($rest, '/') === false)) {
            return ['type' => 'core', 'name' => self::CORE, 'file' => $file];
        }

        return ['type' => 'unknown', 'name' => self::UNKNOWN, 'file' => $file];
    }

    /**
     * @param array<string, array<string, mixed>> $plugins uitvoer van get_plugins()
     * @return array<string, string>
     */
    public static function namesFromPlugins(array $plugins): array
    {
        $names = [];
        foreach ($plugins as $file => $data) {
            $slug = strpos((string) $file, '/') !== false ? explode('/', (string) $file)[0] : (string) $file;
            $name = trim((string) ($data['Name'] ?? ''));
            if ($name !== '' && !isset($names[$slug])) {
                $names[$slug] = $name;
            }
        }

        return $names;
    }

    public static function relative(string $file, string $root): string
    {
        $file = self::normalize($file);
        $rest = self::under($file, $root);

        return $rest ?? $file;
    }

    /** @return array<string, string> */
    private static function pluginNames(): array
    {
        if (self::$plugins !== null) {
            return self::$plugins;
        }
        $cached = function_exists('get_transient') ? get_transient(self::CACHE) : false;
        if (is_array($cached)) {
            return self::$plugins = $cached;
        }
        $names = [];
        try {
            if (!function_exists('get_plugins') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if (function_exists('get_plugins')) {
                $names = self::namesFromPlugins(get_plugins());
            }
        } catch (\Throwable $e) {
            $names = [];
        }
        if (function_exists('set_transient')) {
            set_transient(self::CACHE, $names, 12 * 3600);
        }

        return self::$plugins = $names;
    }

    public static function forgetCache(): void
    {
        self::$plugins = null;
        if (function_exists('delete_transient')) {
            delete_transient(self::CACHE);
        }
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /** Het deel van $file onder $dir (zonder slash ervoor), of null. Hoofdletterongevoelig (Windows). */
    private static function under(string $file, string $dir): ?string
    {
        $dir = self::normalize($dir);
        if ($dir === '') {
            return null;
        }
        $prefix = $dir . '/';
        if (strncasecmp($file, $prefix, strlen($prefix)) !== 0) {
            return null;
        }

        return substr($file, strlen($prefix));
    }
}
