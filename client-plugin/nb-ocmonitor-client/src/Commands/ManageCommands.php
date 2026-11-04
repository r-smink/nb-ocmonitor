<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Commands;

use NextBuzz\OcMonitor\Client\CommandContext;
use NextBuzz\OcMonitor\Client\CommandError;
use NextBuzz\OcMonitor\Client\CommandRegistry;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\MaintenanceMode;

/**
 * Plugins beheren (scope "updates") en de onderhoudsmodus (scope "maintenance"), sinds 0.9.0.
 *
 * Installeren kan alleen vanaf wordpress.org: het panel geeft een slug, de site vraagt zelf het
 * downloadadres op bij api.wordpress.org en accepteert alleen downloads.wordpress.org. Activeren,
 * deactiveren en verwijderen gebeurt via de normale WordPress-functies, zodat de events in het
 * wijzigingslog komen zoals bij een wijziging in WP-admin.
 */
final class ManageCommands
{
    private EventRecorder $recorder;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    public function registerOn(CommandRegistry $registry): void
    {
        $registry->add('plugin.install', 'updates', [$this, 'install']);
        $registry->add('plugin.activate', 'updates', [$this, 'activate']);
        $registry->add('plugin.deactivate', 'updates', [$this, 'deactivate']);
        $registry->add('plugin.delete', 'updates', [$this, 'delete']);
        $registry->add('site.maintenance', 'maintenance', [$this, 'maintenance']);
    }

    /**
     * @param array<string, mixed> $args {slug: string, activate?: bool}
     * @return array<string, mixed>
     */
    public function install(array $args, CommandContext $context): array
    {
        $a = ManageArgs::install($args);
        $this->prepare();
        foreach (get_plugins() as $file => $data) {
            if (strpos($file, $a['slug'] . '/') === 0) {
                return ['status' => 'already_installed', 'file' => $file, 'version' => (string) $data['Version'], 'activated' => $a['activate'] ? $this->activateOne($file) : null];
            }
        }

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        $info = plugins_api('plugin_information', ['slug' => $a['slug'], 'fields' => ['sections' => false, 'versions' => false]]);
        if (is_wp_error($info) || !is_object($info) || empty($info->download_link)) {
            throw new CommandError('not_found', 'Plugin ' . $a['slug'] . ' niet gevonden op wordpress.org', 404);
        }
        $link = (string) $info->download_link;
        if (wp_parse_url($link, PHP_URL_SCHEME) !== 'https' || wp_parse_url($link, PHP_URL_HOST) !== 'downloads.wordpress.org') {
            throw new CommandError('forbidden', 'Alleen pakketten van downloads.wordpress.org', 403);
        }

        $skin = new \WP_Ajax_Upgrader_Skin();
        $result = (new \Plugin_Upgrader($skin))->install($link);
        if ($result !== true) {
            $error = is_wp_error($result) ? $result->get_error_message() : implode('; ', array_map('strval', (array) $skin->get_errors()->get_error_messages()));
            $this->recorder->record('plugin.install', $a['slug'], null, (string) ($info->version ?? ''), 'failed', ['error' => mb_substr($error, 0, 300)]);
            throw new CommandError('install_failed', 'Installeren mislukt: ' . ($error !== '' ? $error : 'onbekende fout'), 500);
        }
        wp_clean_plugins_cache(false);
        $file = null;
        foreach (get_plugins() as $f => $data) {
            if (strpos($f, $a['slug'] . '/') === 0) {
                $file = $f;
                break;
            }
        }
        $this->recorder->record('plugin.install', $a['slug'], null, (string) ($info->version ?? ''), 'success');

        return ['status' => 'installed', 'file' => $file, 'version' => (string) ($info->version ?? ''), 'activated' => $a['activate'] && $file !== null ? $this->activateOne($file) : null];
    }

    /**
     * @param array<string, mixed> $args {plugins: string[]}
     * @return array<string, mixed>
     */
    public function activate(array $args, CommandContext $context): array
    {
        $files = ManageArgs::plugins($args, true);
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $items = [];
        foreach ($files as $file) {
            $items[$file] = $this->activateOne($file);
        }

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed> $args {plugins: string[]}
     * @return array<string, mixed>
     */
    public function deactivate(array $args, CommandContext $context): array
    {
        $files = ManageArgs::plugins($args);
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $installed = get_plugins();
        $items = [];
        foreach ($files as $file) {
            if (!isset($installed[$file])) {
                $items[$file] = 'not_found';
            } elseif (!is_plugin_active($file)) {
                $items[$file] = 'already_inactive';
            } else {
                deactivate_plugins([$file]);
                $items[$file] = is_plugin_active($file) ? 'failed' : 'deactivated';
            }
        }

        return ['items' => $items];
    }

    /**
     * Verwijderen kan alleen voor inactieve plugins: eerst deactiveren is een bewuste tweede stap.
     *
     * @param array<string, mixed> $args {plugins: string[]}
     * @return array<string, mixed>
     */
    public function delete(array $args, CommandContext $context): array
    {
        $files = ManageArgs::plugins($args);
        $this->prepare();
        $installed = get_plugins();
        $items = [];
        $delete = [];
        foreach ($files as $file) {
            if (!isset($installed[$file])) {
                $items[$file] = 'not_found';
            } elseif (is_plugin_active($file)) {
                $items[$file] = 'active';
            } else {
                $delete[] = $file;
            }
        }
        if ($delete !== []) {
            $result = delete_plugins($delete);
            wp_clean_plugins_cache(false);
            $left = get_plugins();
            foreach ($delete as $file) {
                $items[$file] = isset($left[$file]) ? 'failed' : 'deleted';
            }
            if (is_wp_error($result)) {
                return ['items' => $items, 'error' => $result->get_error_message()];
            }
        }

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed> $args {enabled: bool, message?: string, until?: int}
     * @return array<string, mixed>
     */
    public function maintenance(array $args, CommandContext $context): array
    {
        $a = ManageArgs::maintenance($args, $context->now);
        $before = MaintenanceMode::status();
        MaintenanceMode::set($a['enabled'], $a['message'], $a['until']);
        if ($before['enabled'] !== $a['enabled']) {
            $this->recorder->record('site.maintenance', $a['enabled'] ? 'on' : 'off', null, null, 'success', ['until' => $a['until']]);
        }

        return MaintenanceMode::status();
    }

    private function activateOne(string $file): string
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (!isset(get_plugins()[$file])) {
            return 'not_found';
        }
        if (is_plugin_active($file)) {
            return 'already_active';
        }
        $result = activate_plugin($file, '', false, true);

        return is_wp_error($result) ? 'failed: ' . mb_substr($result->get_error_message(), 0, 200) : 'activated';
    }

    private function prepare(): void
    {
        if (!wp_is_file_mod_allowed('nb_ocm_updates')) {
            throw new CommandError('file_mods_disabled', 'Bestandswijzigingen zijn uitgeschakeld op deze site (DISALLOW_FILE_MODS)', 409);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        if (!WP_Filesystem()) {
            throw new CommandError('filesystem', 'WordPress kan hier niet zonder FTP-gegevens schrijven (FS_METHOD of bestandsrechten)', 409);
        }
    }
}
