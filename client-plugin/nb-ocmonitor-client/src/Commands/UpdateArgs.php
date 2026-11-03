<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Commands;

use NextBuzz\OcMonitor\Client\CommandError;

/**
 * Strikte validatie van de argumenten van update-commands. Bewust zonder WordPress, zodat de
 * tests dit los kunnen controleren.
 */
final class UpdateArgs
{
    public const MAX_ITEMS = 10;

    /**
     * @param array<string, mixed> $args {plugins: string[]} pluginbestanden, bv. "akismet/akismet.php"
     * @return string[]
     */
    public static function plugins(array $args): array
    {
        return self::list($args, 'plugins', '/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)?\.php$/D');
    }

    /**
     * @param array<string, mixed> $args {themes: string[]} stylesheets, bv. "twentytwentyfive"
     * @return string[]
     */
    public static function themes(array $args): array
    {
        return self::list($args, 'themes', '/^[A-Za-z0-9._-]+$/D');
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function none(array $args): void
    {
        if ($args !== []) {
            throw new CommandError('bad_request', 'Dit command heeft geen argumenten');
        }
    }

    /**
     * Zelf bijwerken: alleen een versienummer, de sha256 en de release-handtekening. De URL bepaalt
     * de plugin zelf (de panel-URL van de koppeling), zodat het panel geen ander adres kan opgeven.
     *
     * @param array<string, mixed> $args {version: string, sha256: string, signature: string}
     * @return array{version: string, sha256: string, signature: string}
     */
    public static function selfUpdate(array $args): array
    {
        if (array_diff(array_keys($args), ['version', 'sha256', 'signature']) !== []) {
            throw new CommandError('bad_request', 'Alleen version, sha256 en signature zijn toegestaan');
        }
        $version = $args['version'] ?? null;
        $sha = $args['sha256'] ?? null;
        $signature = $args['signature'] ?? null;
        if (!is_string($version) || preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/D', $version) !== 1) {
            throw new CommandError('bad_request', 'Ongeldige versie');
        }
        if (!is_string($sha) || preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1) {
            throw new CommandError('bad_request', 'Ongeldige sha256');
        }
        if (!is_string($signature) || preg_match('/^[A-Za-z0-9_-]{86}$/D', $signature) !== 1) {
            throw new CommandError('bad_request', 'Ongeldige handtekening');
        }

        return ['version' => $version, 'sha256' => $sha, 'signature' => $signature];
    }

    /**
     * @param array<string, mixed> $args
     * @return string[]
     */
    private static function list(array $args, string $name, string $pattern): array
    {
        if (array_diff(array_keys($args), [$name]) !== []) {
            throw new CommandError('bad_request', 'Alleen het argument ' . $name . ' is toegestaan');
        }
        $items = $args[$name] ?? null;
        if (!is_array($items) || $items === [] || array_keys($items) !== range(0, count($items) - 1)) {
            throw new CommandError('bad_request', $name . ' moet een niet-lege lijst zijn');
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new CommandError('bad_request', 'Maximaal ' . self::MAX_ITEMS . ' items per opdracht');
        }
        foreach ($items as $item) {
            if (!is_string($item) || preg_match($pattern, $item) !== 1 || strpos($item, '..') !== false) {
                throw new CommandError('bad_request', 'Ongeldige waarde in ' . $name);
            }
        }

        return array_values(array_unique($items));
    }
}
