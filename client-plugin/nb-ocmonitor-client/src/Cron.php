<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\Events\EventStore;

final class Cron
{
    public const HOURLY = 'nb_ocm_hourly';
    public const TWICE_DAILY = 'nb_ocm_twicedaily';

    public static function register(EventRecorder $recorder, EventStore $store): void
    {
        add_action(self::HOURLY, static function () use ($recorder): void {
            $recorder->flushFailedLoginSummaries(time());
        });
        add_action(self::TWICE_DAILY, static function () use ($recorder, $store): void {
            $now = time();
            $recorder->recordExternalChanges();
            DbNonceStore::cleanup($now);
            $store->cleanup($now);
        });
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOURLY)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOURLY);
        }
        if (!wp_next_scheduled(self::TWICE_DAILY)) {
            wp_schedule_event(time() + 600, 'twicedaily', self::TWICE_DAILY);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOURLY);
        wp_clear_scheduled_hook(self::TWICE_DAILY);
        wp_clear_scheduled_hook('nb_ocm_smtp_log_cleanup'); // Smtp\Log\LogModule::CLEANUP_HOOK
        // Smtp\Alerts\AlertsModule::DISPATCH_HOOK en FLUSH_HOOK: eenmalige taken met argumenten, dus alles wissen.
        wp_unschedule_hook('nb_ocm_smtp_alert_dispatch');
        wp_unschedule_hook('nb_ocm_smtp_alert_flush');
    }
}
