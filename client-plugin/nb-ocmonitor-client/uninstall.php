<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Cron.php';

foreach (['nb_ocm_config', 'nb_ocm_site_key', 'nb_ocm_failures', 'nb_ocm_versions', 'nb_ocm_failed_logins', 'nb_ocm_smtp', 'nb_ocm_smtp_key', 'nb_ocm_smtp_last_test', 'nb_ocm_smtp_backup'] as $option) {
    delete_option($option);
}
delete_option('nb_ocm_smtp_log'); // instellingen e-maillog
delete_transient('nb_ocm_smtp_log_plugins');
// Meldingen (Smtp\Alerts): instellingen, throttle-status, geschiedenis, adminwaarschuwing.
foreach (['nb_ocm_smtp_alerts', 'nb_ocm_smtp_alert_state', 'nb_ocm_smtp_alert_history', 'nb_ocm_smtp_problem'] as $option) {
    delete_option($option);
}
delete_metadata('user', 0, 'nb_ocm_smtp_problem_dismissed', '', true);
NextBuzz\OcMonitor\Client\Cron::unschedule();
NextBuzz\OcMonitor\Client\Schema::uninstall();
