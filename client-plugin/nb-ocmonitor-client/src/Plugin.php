<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

use NextBuzz\OcMonitor\Client\Admin\AdminPage;
use NextBuzz\OcMonitor\Client\Commands\SiteCommands;
use NextBuzz\OcMonitor\Client\Commands\UpdateCommands;
use NextBuzz\OcMonitor\Client\Events\EventPusher;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\Events\EventStore;
use NextBuzz\OcMonitor\Client\Events\VersionSnapshot;

final class Plugin
{
    public static function boot(string $pluginFile): void
    {
        if (defined('NB_OCM_DISABLED') && NB_OCM_DISABLED) {
            self::notice('NB OC Monitor is uitgeschakeld via NB_OCM_DISABLED in wp-config.php. Het panel heeft geen toegang.');
            return;
        }
        if (is_multisite()) {
            self::notice('NB OC Monitor ondersteunt nog geen multisite. De koppeling is niet actief.');
            return;
        }

        register_activation_hook($pluginFile, [Schema::class, 'install']);
        register_deactivation_hook($pluginFile, [Cron::class, 'unschedule']);
        add_action('plugins_loaded', [Schema::class, 'maybeUpgrade']);

        $store = new EventStore();
        $recorder = new EventRecorder($store, new EventPusher(), new VersionSnapshot());
        $recorder->register();
        Cron::register($recorder, $store);

        $commands = new CommandRegistry();
        (new SiteCommands($store, $recorder))->registerOn($commands);
        (new UpdateCommands($recorder))->registerOn($commands);

        add_action('rest_api_init', static function () use ($commands, $recorder): void {
            (new RestController(static function () use ($commands, $recorder): Endpoint {
                return new Endpoint(Settings::load(), new DbNonceStore(), $commands, new OptionFailureLog(), [$recorder, 'setJob']);
            }))->register();
        });

        $pairing = new PairingService($recorder);
        if (is_admin()) {
            (new AdminPage($pairing, $store, $recorder))->register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('nb-ocm', new Cli($pairing, $store, $recorder));
        }
    }

    private static function notice(string $message): void
    {
        add_action('admin_notices', static function () use ($message): void {
            if (current_user_can('manage_options')) {
                printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html($message));
            }
        });
    }
}
