<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

final class Schema
{
    public const VERSION = '2';
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

    public static function mailLogTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'nb_ocm_mail_log';
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
        $mailLog = self::mailLogTable();

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

        // E-maillog van de SMTP-module (Smtp\Log). status: pending | sent | failed.
        dbDelta("CREATE TABLE {$mailLog} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at INT UNSIGNED NOT NULL,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            connection VARCHAR(16) NOT NULL DEFAULT '',
            is_test TINYINT(1) NOT NULL DEFAULT 0,
            is_alert TINYINT(1) NOT NULL DEFAULT 0,
            to_email TEXT NOT NULL,
            cc TEXT NULL,
            bcc TEXT NULL,
            from_email VARCHAR(255) NOT NULL DEFAULT '',
            subject TEXT NOT NULL,
            headers TEXT NULL,
            sent_headers TEXT NULL,
            content LONGTEXT NULL,
            content_type VARCHAR(64) NOT NULL DEFAULT '',
            attachments TEXT NULL,
            message_id VARCHAR(255) NOT NULL DEFAULT '',
            initiator VARCHAR(191) NOT NULL DEFAULT '',
            initiator_file VARCHAR(255) NOT NULL DEFAULT '',
            error TEXT NULL,
            backup_error TEXT NULL,
            diagnose_code VARCHAR(32) NOT NULL DEFAULT '',
            resent_of BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY status_created (status, created_at)
        ) {$charset};");

        update_option(self::VERSION_OPTION, self::VERSION, false);
        Cron::schedule();
    }

    public static function uninstall(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::eventsTable()); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DROP TABLE IF EXISTS ' . self::noncesTable()); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DROP TABLE IF EXISTS ' . self::mailLogTable()); // phpcs:ignore WordPress.DB.PreparedSQL
        delete_option(self::VERSION_OPTION);
    }
}
