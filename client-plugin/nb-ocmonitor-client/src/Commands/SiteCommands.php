<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Commands;

use NextBuzz\OcMonitor\Client\CommandContext;
use NextBuzz\OcMonitor\Client\CommandError;
use NextBuzz\OcMonitor\Client\CommandRegistry;
use NextBuzz\OcMonitor\Client\Security\LoginSecurity;
use NextBuzz\OcMonitor\Client\Security\TwoFactor;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\Events\EventStore;

/**
 * Lezen en events. Muterende commands staan in eigen klassen met een eigen scope (UpdateCommands).
 */
final class SiteCommands
{
    private EventStore $events;
    private EventRecorder $recorder;

    public function __construct(EventStore $events, EventRecorder $recorder)
    {
        $this->events = $events;
        $this->recorder = $recorder;
    }

    public function registerOn(CommandRegistry $registry): void
    {
        $registry->add('site.info', 'read', [$this, 'info']);
        $registry->add('site.inventory', 'read', [$this, 'inventory']);
        $registry->add('events.pull', 'events', [$this, 'pullEvents']);
        $registry->add('events.ack', 'events', [$this, 'ackEvents']);
    }

    /**
     * Licht genoeg om als heartbeat te dienen.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function info(array $args, CommandContext $context): array
    {
        global $wp_version;

        return [
            'site_id' => $context->config->siteId,
            'home_url' => home_url(),
            'wp_version' => (string) $wp_version,
            'php_version' => PHP_VERSION,
            'plugin_version' => NB_OCM_VERSION,
            'last_event_seq' => $this->events->lastSeq(),
            'scopes' => $context->config->scopes,
            'time' => $context->now,
        ];
    }

    /**
     * @param array<string, mixed> $args {refresh?: bool} refresh = eerst bij wordpress.org naar updates kijken
     * @return array<string, mixed>
     */
    public function inventory(array $args, CommandContext $context): array
    {
        global $wp_version, $wpdb;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        if (!empty($args['refresh'])) {
            wp_version_check([], true);
            wp_update_plugins();
            wp_update_themes();
        }

        // Wijzigingen buiten WordPress om meteen zichtbaar maken, vóór de inventaris.
        $this->recorder->recordExternalChanges();

        $pluginUpdates = self::transientList('update_plugins', 'response');
        $themeUpdates = self::transientList('update_themes', 'response');
        $coreUpdates = self::transientList('update_core', 'updates');
        $autoUpdatePlugins = (array) get_site_option('auto_update_plugins', []);
        $autoUpdateThemes = (array) get_site_option('auto_update_themes', []);

        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $update = $pluginUpdates[$file] ?? null;
            $plugins[] = [
                'file' => $file,
                'slug' => dirname($file) !== '.' ? dirname($file) : basename($file, '.php'),
                'name' => (string) $data['Name'],
                'version' => (string) $data['Version'],
                'active' => is_plugin_active($file),
                'auto_update' => in_array($file, $autoUpdatePlugins, true),
                'update' => $update ? ['version' => (string) ($update->new_version ?? ''), 'package_host' => self::host($update->package ?? '')] : null,
                'updated_at' => self::mtime(WP_PLUGIN_DIR . '/' . $file),
            ];
        }

        $themes = [];
        $active = get_stylesheet();
        foreach (wp_get_themes() as $stylesheet => $theme) {
            // Normaal een array; sommige premium-updaters zetten er een object in.
            $update = isset($themeUpdates[$stylesheet]) ? (array) $themeUpdates[$stylesheet] : null;
            $themes[] = [
                'stylesheet' => $stylesheet,
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'active' => $stylesheet === $active,
                'parent' => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
                'auto_update' => in_array($stylesheet, $autoUpdateThemes, true),
                'update' => $update && !empty($update['new_version']) ? ['version' => (string) $update['new_version'], 'package_host' => self::host((string) ($update['package'] ?? ''))] : null,
                'updated_at' => self::mtime($theme->get_stylesheet_directory() . '/style.css'),
            ];
        }

        $coreUpdate = null;
        foreach ($coreUpdates as $offer) {
            if (is_object($offer) && ($offer->response ?? '') === 'upgrade') {
                $coreUpdate = ['version' => (string) $offer->current];
                break;
            }
        }

        $users = count_users();

        return [
            'core' => ['version' => (string) $wp_version, 'update' => $coreUpdate, 'locale' => get_locale()],
            'php_version' => PHP_VERSION,
            'db_server' => (string) $wpdb->db_server_info(),
            'plugins' => $plugins,
            'mu_plugins' => array_map(static function ($file, $data) {
                return ['file' => $file, 'name' => (string) $data['Name'], 'version' => (string) $data['Version']];
            }, array_keys(get_mu_plugins()), get_mu_plugins()),
            'themes' => $themes,
            'cleanup' => CleanupCommands::stats(),
            'translations_pending' => count(wp_get_translation_updates()),
            'users' => ['total' => (int) $users['total_users'], 'by_role' => $users['avail_roles']],
            'environment' => [
                'https' => wp_parse_url(home_url(), PHP_URL_SCHEME) === 'https',
                'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
                'disallow_file_edit' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
                'auto_update_core' => defined('WP_AUTO_UPDATE_CORE') ? WP_AUTO_UPDATE_CORE : null,
                'blog_public' => (bool) get_option('blog_public'),
                'users_can_register' => (bool) get_option('users_can_register'),
                'default_role' => (string) get_option('default_role'),
                'login_security' => LoginSecurity::summary(),
                'two_factor' => TwoFactor::disabledByConstant() ? 'disabled' : TwoFactor::mode(),
                'file_mods_allowed' => wp_is_file_mod_allowed('nb_ocm_updates'),
                'plugin_version' => NB_OCM_VERSION,
            ],
            'last_event_seq' => $this->events->lastSeq(),
        ];
    }

    /**
     * @param array<string, mixed> $args {after_seq: int, limit?: int}
     * @return array<string, mixed>
     */
    public function pullEvents(array $args, CommandContext $context): array
    {
        $after = self::intArg($args, 'after_seq', 0);
        $limit = min(500, max(1, self::intArg($args, 'limit', 200)));
        $events = $this->events->after($after, $limit);

        return [
            'events' => $events,
            'last_seq' => $this->events->lastSeq(),
            'more' => count($events) === $limit,
        ];
    }

    /**
     * @param array<string, mixed> $args {up_to_seq: int}
     * @return array<string, mixed>
     */
    public function ackEvents(array $args, CommandContext $context): array
    {
        return ['acked' => $this->events->ack(self::intArg($args, 'up_to_seq', 0))];
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function intArg(array $args, string $name, int $default): int
    {
        if (!array_key_exists($name, $args)) {
            return $default;
        }
        if (!is_int($args[$name]) || $args[$name] < 0) {
            throw new CommandError('bad_request', $name . ' moet een geheel getal ≥ 0 zijn');
        }

        return $args[$name];
    }

    /**
     * @return array<mixed> het opgegeven veld van een update-transient, of [] als die ontbreekt
     */
    private static function transientList(string $transient, string $field): array
    {
        $value = get_site_transient($transient);

        return is_object($value) && isset($value->{$field}) ? (array) $value->{$field} : [];
    }

    /** Wanneer de bestanden van een plugin of thema voor het laatst zijn geschreven (installatie of update). */
    private static function mtime(string $file): ?int
    {
        $time = @filemtime($file);

        return $time !== false ? (int) $time : null;
    }

    /** Alleen de host van een download-URL; volledige URL's kunnen licentiesleutels bevatten. */
    private static function host(string $url): ?string
    {
        $host = wp_parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }
}
