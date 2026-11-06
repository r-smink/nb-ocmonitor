<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

use NextBuzz\OcMonitor\Client\Admin\AdminPage;
use NextBuzz\OcMonitor\Client\Backup\BackupCommands;
use NextBuzz\OcMonitor\Client\Commands\SiteCommands;
use NextBuzz\OcMonitor\Client\Commands\CleanupCommands;
use NextBuzz\OcMonitor\Client\Commands\LicenseCommands;
use NextBuzz\OcMonitor\Client\Commands\UpdateCommands;
use NextBuzz\OcMonitor\Client\Commands\UserCommands;
use NextBuzz\OcMonitor\Client\Commands\ManageCommands;
use NextBuzz\OcMonitor\Client\Commands\IntegrityCommands;
use NextBuzz\OcMonitor\Client\Security\TwoFactor;
use NextBuzz\OcMonitor\Client\Events\EventPusher;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\Events\EventStore;
use NextBuzz\OcMonitor\Client\Events\VersionSnapshot;
use NextBuzz\OcMonitor\Client\Security\LoginSecurity;

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
        (new LoginSecurity($recorder))->register();
        (new TwoFactor($recorder))->register();
        MaintenanceMode::register();
        Tweaks::register();
        ErrorCatcher::register();
        Admin\DashboardWidget::register();
        $panelLogin = new Security\PanelLogin($recorder);
        $panelLogin->register();
        $users = new UserCommands($recorder);
        $users->register();
        Cron::register($recorder, $store);

        $commands = new CommandRegistry();
        (new SiteCommands($store, $recorder))->registerOn($commands);
        (new UpdateCommands($recorder))->registerOn($commands);
        $users->registerOn($commands);
        (new BackupCommands($recorder))->registerOn($commands);
        (new CleanupCommands($recorder))->registerOn($commands);
        (new LicenseCommands($recorder))->registerOn($commands);
        (new ManageCommands($recorder))->registerOn($commands);
        (new IntegrityCommands())->registerOn($commands);
        $commands->add('auth.login_link', 'users', static function (array $args) use ($panelLogin): array {
            return $panelLogin->link($args);
        });
        $commands->add('errors.list', 'read', static function (): array {
            return ErrorCatcher::list();
        });
        $commands->add('site.schedule', 'maintenance', static function (array $args): array {
            return Admin\DashboardWidget::store($args);
        });
        $commands->add('tweaks.set', 'maintenance', static function (array $args) use ($recorder): array {
            return Tweaks::set($args, $recorder);
        });

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
