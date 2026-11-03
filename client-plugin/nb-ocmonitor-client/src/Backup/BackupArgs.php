<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Backup;

use NextBuzz\OcMonitor\Client\CommandError;
use NextBuzz\OcMonitor\Client\Commands\UpdateArgs;

/**
 * Validatie van backup-commands, zonder WordPress (voor de tests).
 *
 * Het panel stuurt alleen tijdelijke https-links (presigned Spaces-URL's) en welke onderdelen er in
 * de backup moeten. De sleutel stuurt het panel NIET: die staat op de site zelf. Een gehackt panel
 * kan dus wel een backup laten uploaden, maar die niet lezen.
 */
final class BackupArgs
{
    /**
     * @param array<string, mixed> $args
     * @return array{backup_id: string, plugins: string[], themes: string[], core: bool, uploads: array<string, array{url: string, headers: array<string, string>}>}
     */
    public static function create(array $args): array
    {
        self::only($args, ['backup_id', 'plugins', 'themes', 'core', 'uploads']);
        $plugins = isset($args['plugins']) && $args['plugins'] !== [] ? UpdateArgs::plugins(['plugins' => $args['plugins']]) : [];
        $themes = isset($args['themes']) && $args['themes'] !== [] ? UpdateArgs::themes(['themes' => $args['themes']]) : [];
        if (isset($args['core']) && !is_bool($args['core'])) {
            throw new CommandError('bad_request', 'core moet true of false zijn');
        }

        return [
            'backup_id' => self::id($args['backup_id'] ?? null),
            'plugins' => $plugins,
            'themes' => $themes,
            'core' => !empty($args['core']),
            'uploads' => self::links($args['uploads'] ?? null, ['db', 'files']),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{backup_id: string, parts: string[], downloads: array<string, array{url: string, headers: array<string, string>}>, sha256: array<string, string>}
     */
    public static function restore(array $args): array
    {
        self::only($args, ['backup_id', 'parts', 'downloads', 'sha256']);
        $parts = $args['parts'] ?? null;
        if (!is_array($parts) || !in_array($parts, [['files'], ['db'], ['files', 'db']], true)) {
            throw new CommandError('bad_request', 'parts moet ["files"], ["db"] of ["files","db"] zijn');
        }
        $sha = $args['sha256'] ?? null;
        foreach ($parts as $part) {
            if (!is_array($sha) || !is_string($sha[$part] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $sha[$part]) !== 1) {
                throw new CommandError('bad_request', 'sha256 ontbreekt voor ' . $part);
            }
        }

        return [
            'backup_id' => self::id($args['backup_id'] ?? null),
            'parts' => $parts,
            'downloads' => self::links($args['downloads'] ?? null, $parts),
            'sha256' => array_intersect_key($sha, array_flip($parts)),
        ];
    }

    /** @param mixed $id */
    private static function id($id): string
    {
        if (!is_string($id) || preg_match('/^bk_[a-z0-9]{20}$/D', $id) !== 1) {
            throw new CommandError('bad_request', 'Ongeldig backup_id');
        }

        return $id;
    }

    /**
     * @param mixed $links
     * @param string[] $required
     * @return array<string, array{url: string, headers: array<string, string>}>
     */
    private static function links($links, array $required): array
    {
        if (!is_array($links) || array_diff(array_keys($links), $required) !== [] || array_diff($required, array_keys($links)) !== []) {
            throw new CommandError('bad_request', 'Links ontbreken of zijn onbekend');
        }
        $out = [];
        foreach ($required as $name) {
            $link = $links[$name];
            $url = is_array($link) ? ($link['url'] ?? null) : null;
            if (!is_string($url) || strlen($url) > 4096 || preg_match('#^https://[a-z0-9.-]+(:\d+)?/#i', $url) !== 1) {
                throw new CommandError('bad_request', 'Alleen https-links zijn toegestaan');
            }
            $headers = [];
            foreach ((array) ($link['headers'] ?? []) as $key => $value) {
                if (!is_string($key) || !is_string($value) || preg_match('/^[A-Za-z0-9-]{1,64}$/D', $key) !== 1 || preg_match('/[\r\n]/', $value) === 1 || strlen($value) > 1024) {
                    throw new CommandError('bad_request', 'Ongeldige header');
                }
                $headers[$key] = $value;
            }
            $out[$name] = ['url' => $url, 'headers' => $headers];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $args
     * @param string[] $allowed
     */
    private static function only(array $args, array $allowed): void
    {
        if (array_diff(array_keys($args), $allowed) !== []) {
            throw new CommandError('bad_request', 'Alleen ' . implode(', ', $allowed) . ' zijn toegestaan');
        }
    }
}
