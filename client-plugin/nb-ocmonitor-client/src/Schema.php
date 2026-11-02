<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

final class Schema
{
    public const VERSION = '1';
    private const VERSION_OPTION = 'nb_ocm_db_version';

    public static function eventsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'nb_ocm_events';
    }

    public static function noncesTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'nb_ocm_nonces';
    }

    public static function maybeUpgrade(): void
    {
        if (get_option(self::VERSION_OPTION) !== self::VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $events = self::eventsTable();
        $nonces = self::noncesTable();

        // seq is het oplopende volgnummer waarmee het panel gaten detecteert.
        dbDelta("CREATE TABLE {$events} (
            seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at INT UNSIGNED NOT NULL,
            type VARCHAR(64) NOT NULL,
            object VARCHAR(191) NOT NULL DEFAULT '',
            from_version VARCHAR(191) NULL,
            to_version VARCHAR(191) NULL,
            result VARCHAR(16) NOT NULL DEFAULT 'success',
            source VARCHAR(16) NOT NULL,
            job_id VARCHAR(64) NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_login VARCHAR(60) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            data TEXT NULL,
            acked TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (seq),
            KEY acked_seq (acked, seq),
            KEY created_at (created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$nonces} (
            nonce VARCHAR(64) NOT NULL,
            expires_at INT UNSIGNED NOT NULL,
            PRIMARY KEY  (nonce),
            KEY expires_at (expires_at)
        ) {$charset};");

        update_option(self::VERSION_OPTION, self::VERSION, false);
        Cron::schedule();
    }

    public static function uninstall(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::eventsTable()); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DROP TABLE IF EXISTS ' . self::noncesTable()); // phpcs:ignore WordPress.DB.PreparedSQL
        delete_option(self::VERSION_OPTION);
    }
}
