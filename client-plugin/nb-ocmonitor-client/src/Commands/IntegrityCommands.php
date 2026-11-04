<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Commands;

use NextBuzz\OcMonitor\Client\CommandContext;
use NextBuzz\OcMonitor\Client\CommandRegistry;

/**
 * Integriteitscontrole (security.integrity, scope "read", sinds 0.9.0). Alleen lezen:
 *
 * - WordPress core tegen de checksums van api.wordpress.org (gewijzigd, ontbrekend, extra PHP);
 * - PHP-bestanden in de hoofdmap die niet bij WordPress horen;
 * - PHP-bestanden in uploads (daar hoort geen code te staan: bijna altijd een webshell);
 * - plugins van wordpress.org tegen downloads.wordpress.org/plugin-checksums.
 *
 * Er wordt niets verwijderd of gewijzigd; het panel toont wat er afwijkt.
 */
final class IntegrityCommands
{
    /** Zoveel seconden voor de plugins; daarna "skipped", zodat het command niet te lang duurt. */
    private const PLUGIN_BUDGET_SECONDS = 90;

    private const MAX_UPLOAD_FILES = 200000;

    public function registerOn(CommandRegistry $registry): void
    {
        $registry->add('security.integrity', 'read', [$this, 'check']);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function check(array $args, CommandContext $context): array
    {
        UpdateArgs::none($args);
        global $wp_version;
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        $started = microtime(true);
        $root = rtrim(ABSPATH, '/\\') . '/';

        $core = null;
        $rootUnknown = [];
        // Vertaalde builds hebben eigen checksums; anders die van en_US.
        $checksums = get_core_checksums($wp_version, get_locale());
        if (!is_array($checksums) || $checksums === []) {
            $checksums = get_core_checksums($wp_version, 'en_US');
        }
        if (is_array($checksums) && $checksums !== []) {
            $checksums = IntegrityCheck::coreOnly($checksums);
            $present = array_merge(self::listFiles($root . 'wp-admin', 'wp-admin/'), self::listFiles($root . 'wp-includes', 'wp-includes/'));
            $core = IntegrityCheck::compare($checksums, static function (string $path) use ($root): ?string {
                return is_file($root . $path) ? (string) md5_file($root . $path) : null;
            }, $present);
            // wp-config-sample.php e.d. mogen ontbreken; verwijderd zijn is geen risico.
            $core['missing'] = array_values(array_filter($core['missing'], static function (string $p): bool {
                return !in_array($p, ['wp-config-sample.php', 'readme.html', 'license.txt', 'wp-admin/install.php'], true);
            }));
            $core['counts']['missing'] = count($core['missing']);
            $rootFiles = array_values(array_filter((array) scandir($root), static function ($f) use ($root): bool {
                return is_string($f) && is_file($root . $f);
            }));
            $rootUnknown = IntegrityCheck::unknownRootPhp($rootFiles, $checksums);
        }

        $uploads = wp_get_upload_dir();
        $uploadsPhp = [];
        if (!empty($uploads['basedir']) && is_dir($uploads['basedir'])) {
            $scanned = 0;
            foreach (self::iterate($uploads['basedir']) as $relative) {
                if (++$scanned > self::MAX_UPLOAD_FILES) {
                    break;
                }
                if (IntegrityCheck::isPhp($relative) && basename($relative) !== 'index.php') {
                    $uploadsPhp[] = $relative;
                }
            }
            sort($uploadsPhp);
        }

        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.' || $slug === ManageArgs::SELF_SLUG) {
                continue;
            }
            if (microtime(true) - $started > self::PLUGIN_BUDGET_SECONDS) {
                $plugins[$slug] = ['status' => 'skipped'];
                continue;
            }
            $plugins[$slug] = self::checkPlugin($slug, (string) $data['Version']);
        }

        return [
            'checked_at' => $context->now,
            'wp_version' => (string) $wp_version,
            'core' => $core,
            'root_unknown' => array_slice($rootUnknown, 0, IntegrityCheck::MAX_REPORTED),
            'uploads_php' => array_slice($uploadsPhp, 0, IntegrityCheck::MAX_REPORTED),
            'uploads_php_count' => count($uploadsPhp),
            'plugins' => $plugins,
            'seconds' => round(microtime(true) - $started, 1),
        ];
    }

    /** @return array<string, mixed> */
    private static function checkPlugin(string $slug, string $version): array
    {
        $url = 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode($slug) . '/' . rawurlencode($version) . '.json';
        $response = wp_remote_get($url, ['timeout' => 10, 'redirection' => 0]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            // Premium-plugin of versie die wordpress.org niet kent: niet te controleren.
            return ['status' => 'unverifiable'];
        }
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        $checksums = is_array($json) ? IntegrityCheck::pluginChecksums($json) : [];
        if ($checksums === []) {
            return ['status' => 'unverifiable'];
        }
        $dir = WP_PLUGIN_DIR . '/' . $slug . '/';
        $result = IntegrityCheck::compare($checksums, static function (string $path) use ($dir): ?string {
            return is_file($dir . $path) ? (string) md5_file($dir . $path) : null;
        }, self::listFiles(rtrim($dir, '/'), ''));
        $bad = $result['counts']['modified'] + $result['counts']['unknown'];

        return [
            'status' => $bad > 0 ? 'modified' : 'ok',
            'modified' => array_slice($result['modified'], 0, 20),
            'unknown' => array_slice($result['unknown'], 0, 20),
            'missing' => $result['counts']['missing'],
            'counts' => $result['counts'],
        ];
    }

    /** @return string[] relatieve paden van alle bestanden onder $dir */
    private static function listFiles(string $dir, string $prefix): array
    {
        $out = [];
        foreach (self::iterate($dir) as $relative) {
            $out[] = $prefix . $relative;
        }

        return $out;
    }

    /** @return \Generator<int, string> */
    private static function iterate(string $dir): \Generator
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS));
        $base = strlen(rtrim(str_replace('\\', '/', $dir), '/')) + 1;
        foreach ($it as $file) {
            if ($file->isFile()) {
                yield substr(str_replace('\\', '/', $file->getPathname()), $base);
            }
        }
    }
}
