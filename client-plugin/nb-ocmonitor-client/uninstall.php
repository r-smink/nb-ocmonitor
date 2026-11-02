<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Cron.php';

foreach (['nb_ocm_config', 'nb_ocm_site_key', 'nb_ocm_failures', 'nb_ocm_versions', 'nb_ocm_failed_logins'] as $option) {
    delete_option($option);
}
NextBuzz\OcMonitor\Client\Cron::unschedule();
NextBuzz\OcMonitor\Client\Schema::uninstall();
