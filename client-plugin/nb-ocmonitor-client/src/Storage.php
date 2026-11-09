<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

/**
 * Hoeveel ruimte de site inneemt, voor de trendgrafieken in het panel. Het tellen van wp-content
 * kost tijd op grote sites; daarom hooguit één keer per dag, met een tijdslimiet. Is de limiet
 * bereikt, dan staat partial op true en is de waarde een ondergrens.
 */
final class Storage
{
    private const TRANSIENT = 'nb_ocm_storage';
    private const MAX_SECONDS = 8.0;

    /**
     * @return array{content_bytes: ?int, uploads_bytes: ?int, files: int, partial: bool, disk_free_bytes: ?int, measured_at: int}
     */
    public static function summary(): array
    {
        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached) && isset($cached['measured_at'])) {
            return $cached;
        }

        $deadline = microtime(true) + self::MAX_SECONDS;
        $uploadsDir = (string) (wp_upload_dir(null, false)['basedir'] ?? '');
        $files = 0;
        $uploads = $uploadsDir !== '' ? self::directorySize($uploadsDir, $deadline, $files) : null;
        $uploadsPartial = microtime(true) >= $deadline;
        $content = self::directorySize(WP_CONTENT_DIR, $deadline, $files);
        $free = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors

        $result = [
            'content_bytes' => $content,
            'uploads_bytes' => $uploads,
            'files' => $files,
            'partial' => $uploadsPartial || microtime(true) >= $deadline,
            'disk_free_bytes' => is_float($free) || is_int($free) ? (int) $free : null,
            'measured_at' => time(),
        ];
        set_transient(self::TRANSIENT, $result, DAY_IN_SECONDS);

        return $result;
    }

    /**
     * Totale grootte van een map in bytes, zonder symlinks te volgen. Stopt bij $deadline
     * (microtime) en geeft dan wat er tot dan geteld is.
     */
    public static function directorySize(string $dir, float $deadline, int &$files): ?int
    {
        if (!is_dir($dir) || !is_readable($dir)) {
            return null;
        }
        $total = 0;
        $stack = [$dir];
        while ($stack !== []) {
            $current = array_pop($stack);
            $handle = @opendir($current); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            if ($handle === false) {
                continue;
            }
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $current . DIRECTORY_SEPARATOR . $entry;
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    $stack[] = $path;
                } else {
                    $size = @filesize($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                    $total += $size === false ? 0 : $size;
                    $files++;
                }
                if (($files & 255) === 0 && microtime(true) >= $deadline) {
                    closedir($handle);

                    return $total;
                }
            }
            closedir($handle);
        }

        return $total;
    }
}
