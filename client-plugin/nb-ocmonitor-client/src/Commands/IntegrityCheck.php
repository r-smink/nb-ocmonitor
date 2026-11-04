<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Commands;

/**
 * Vergelijkt bestanden met de checksums van wordpress.org. Zonder WordPress, zodat de tests dit
 * los kunnen controleren. Alleen PHP-achtige bestanden tellen als "onbekend": een losse .txt of
 * afbeelding is geen risico, een extra .php wel.
 */
final class IntegrityCheck
{
    public const MAX_REPORTED = 100;

    public const PHP_EXTENSIONS = ['php', 'phtml', 'php5', 'php7', 'phar', 'phps'];

    /**
     * @param array<string, string> $checksums relatief pad => md5 (of sha256)
     * @param callable(string): ?string $hashOf relatief pad => hash, of null als het bestand ontbreekt
     * @param string[] $present bestaande bestanden (relatieve paden) in dezelfde mappen
     * @return array{modified: string[], missing: string[], unknown: string[], checked: int}
     */
    public static function compare(array $checksums, callable $hashOf, array $present): array
    {
        $modified = [];
        $missing = [];
        foreach ($checksums as $path => $expected) {
            $actual = $hashOf($path);
            if ($actual === null) {
                $missing[] = $path;
            } else {
                $match = false;
                foreach (explode(',', strtolower($expected)) as $candidate) {
                    $match = $match || hash_equals($candidate, strtolower($actual));
                }
                if (!$match) {
                    $modified[] = $path;
                }
            }
        }
        $unknown = [];
        foreach ($present as $path) {
            if (!isset($checksums[$path]) && self::isPhp($path)) {
                $unknown[] = $path;
            }
        }
        sort($modified);
        sort($missing);
        sort($unknown);

        return [
            'modified' => array_slice($modified, 0, self::MAX_REPORTED),
            'missing' => array_slice($missing, 0, self::MAX_REPORTED),
            'unknown' => array_slice($unknown, 0, self::MAX_REPORTED),
            'checked' => count($checksums),
            'counts' => ['modified' => count($modified), 'missing' => count($missing), 'unknown' => count($unknown)],
        ];
    }

    public static function isPhp(string $path): bool
    {
        return in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), self::PHP_EXTENSIONS, true);
    }

    /**
     * Core-checksums: alleen wp-admin, wp-includes en de bestanden in de hoofdmap. wp-content
     * (thema's, plugins, uploads) hoort niet bij core en mag afwijken.
     *
     * @param array<string, string> $checksums
     * @return array<string, string>
     */
    public static function coreOnly(array $checksums): array
    {
        return array_filter($checksums, static function (string $path): bool {
            return strpos($path, 'wp-content/') !== 0;
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Bestanden in de hoofdmap die niet bij core horen, behalve de bekende eigen bestanden.
     *
     * @param string[] $rootFiles bestandsnamen in ABSPATH
     * @param array<string, string> $coreChecksums
     * @return string[]
     */
    public static function unknownRootPhp(array $rootFiles, array $coreChecksums): array
    {
        $own = ['wp-config.php', 'wp-config-sample.php', 'local-config.php', 'wp-cli.phar'];
        $out = [];
        foreach ($rootFiles as $file) {
            if (self::isPhp($file) && !isset($coreChecksums[$file]) && !in_array(strtolower($file), $own, true)) {
                $out[] = $file;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Plugin-checksums van downloads.wordpress.org: {"files": {"pad": {"md5": "...", "sha256": "..."}}}.
     *
     * @param array<string, mixed> $json
     * @return array<string, string> pad => md5
     */
    public static function pluginChecksums(array $json): array
    {
        $out = [];
        foreach ((array) ($json['files'] ?? []) as $path => $hashes) {
            $md5 = is_array($hashes) ? ($hashes['md5'] ?? null) : null;
            // Soms meerdere md5's (verschillende builds van dezelfde versie): de eerste is genoeg om te tonen,
            // maar elke geldige telt. We houden ze bij elkaar met een komma.
            if (is_array($md5)) {
                $md5 = implode(',', array_filter($md5, 'is_string'));
            }
            if (is_string($path) && is_string($md5) && $md5 !== '' && strpos($path, '..') === false) {
                $out[$path] = $md5;
            }
        }

        return $out;
    }
}
