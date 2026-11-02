<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Events;

/**
 * Laatst bekende versies van core, plugins, mu-plugins en thema's. Wijzigingen zonder bijbehorende
 * hook (FTP, Plesk-installer, deploy, restore) komen zo toch boven.
 */
final class VersionSnapshot
{
    private const OPTION = 'nb_ocm_versions';

    /**
     * @return array<string, string> sleutel "type:id" => versie
     */
    public function current(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        wp_clean_plugins_cache(false);
        wp_clean_themes_cache(false);

        $versions = ['core:wordpress' => self::coreVersionFromDisk()];
        foreach (get_plugins() as $file => $data) {
            $versions['plugin:' . $file] = (string) ($data['Version'] ?? '');
        }
        foreach (get_mu_plugins() as $file => $data) {
            $versions['mu-plugin:' . $file] = (string) ($data['Version'] ?? '');
        }
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $versions['theme:' . $stylesheet] = (string) $theme->get('Version');
        }
        ksort($versions);

        return $versions;
    }

    /**
     * Vergelijkt met de opgeslagen snapshot en slaat daarna de huidige op.
     *
     * @param string[] $knownKeys sleutels waarvan de wijziging al via een hook is vastgelegd
     * @return list<array{key: string, from: ?string, to: ?string}> onverklaarde wijzigingen
     */
    public function detectChanges(array $knownKeys = []): array
    {
        $stored = get_option(self::OPTION);
        $current = $this->current();
        update_option(self::OPTION, $current, false);

        if (!is_array($stored)) {
            return []; // eerste meting: alleen een basislijn
        }

        $changes = [];
        foreach (array_unique(array_merge(array_keys($stored), array_keys($current))) as $key) {
            $from = isset($stored[$key]) ? (string) $stored[$key] : null;
            $to = $current[$key] ?? null;
            if ($from !== $to && !in_array($key, $knownKeys, true)) {
                $changes[] = ['key' => (string) $key, 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /** Leest de versie van schijf: $wp_version in het geheugen is na een core-update nog de oude. */
    private static function coreVersionFromDisk(): string
    {
        $wp_version = '';
        include ABSPATH . WPINC . '/version.php';

        return (string) $wp_version;
    }
}
