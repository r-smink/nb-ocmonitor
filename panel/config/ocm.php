<?php

return [
    // Publieke panel-sleutel (base64url). De web-app heeft alleen deze nodig (koppelcodes).
    'panel_public_key' => env('OCM_PANEL_PUBLIC_KEY'),

    // Private signing key (base64url). ALLEEN in de signer-container zetten (signer.env), nooit in .env.
    'signing_key' => env('OCM_SIGNING_KEY'),

    // Pad naar de client-plugin-map waaruit de download-zip gebouwd wordt.
    'client_plugin_path' => env('OCM_CLIENT_PLUGIN_PATH', base_path('../client-plugin/nb-ocmonitor-client')),

    // Ondertekende releases (scripts/release-plugin.php). Sites met 0.4.0+ werken zichzelf hiermee bij.
    'client_plugin_dist' => env('OCM_CLIENT_PLUGIN_DIST', base_path('../client-plugin/dist')),

    // Alleen voor lokaal testen: sites via http toestaan.
    'allow_http_sites' => (bool) env('OCM_ALLOW_HTTP_SITES', false),

    'pairing_lifetime_minutes' => 15,
    'heartbeat_minutes' => (int) env('OCM_HEARTBEAT_MINUTES', 15),

    // Updates: zoveel componenten per job (de plugin accepteert er maximaal 10).
    'updates' => [
        'chunk_size' => 5,
        'min_plugin_version' => '0.2.0',
    ],

    // Google PageSpeed Insights API v5. Zonder sleutel werkt de API ook, maar met een laag quotum.
    'pagespeed' => [
        'api_key' => env('OCM_PAGESPEED_API_KEY'),
        'interval_days' => (int) env('OCM_PAGESPEED_INTERVAL_DAYS', 7),
        'sites_per_run' => 6,
        'timeout_seconds' => 90,
        'drop_alert_points' => 10,
    ],

    // Uptime, SSL en domein (ocm:monitor in de scheduler).
    'monitoring' => [
        'interval_minutes' => 5,
        'timeout_seconds' => 20,
        'ssl_warn_days' => 14,
        'domain_warn_days' => 30,
        'keep_days' => 120,
    ],

    // Visuele controle bij updates: screenshots via PageSpeed Insights (alleen met API-sleutel).
    'visual' => [
        'enabled' => (bool) env('OCM_VISUAL_CHECKS', true),
        'threshold_percent' => (float) env('OCM_VISUAL_THRESHOLD', 15),
        'hold_minutes' => 8,
        'settle_seconds' => 60,
    ],

    // Backup vóór updates, in DigitalOcean Spaces (disk "backups" in config/filesystems.php).
    'backups' => [
        'enabled' => (bool) env('OCM_BACKUPS', true),
        'keep_days' => (int) env('OCM_BACKUP_KEEP_DAYS', 7),
        // Updates binnen zoveel uur na een volledige backup gebruiken die backup (geen losse backup per update).
        'full_reuse_hours' => (int) env('OCM_BACKUP_FULL_REUSE_HOURS', 3),
        'link_minutes' => 180,
        'max_minutes' => 90,
    ],

    // Wordfence Intelligence Vulnerability Data Feed (v3): gratis API-sleutel via wordfence.com → Account → Integrations.
    'vulnerabilities' => [
        'api_key' => env('OCM_WORDFENCE_API_KEY'),
        'endpoint' => env('OCM_WORDFENCE_FEED', 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production'),
    ],

    // Meldingen (ocm:alerts): pas melden als een probleem zo lang open staat; herinnering bij kritiek.
    'alerts' => [
        'enabled' => (bool) env('OCM_ALERTS', true),
        'delay_minutes' => (int) env('OCM_ALERT_DELAY_MINUTES', 10),
        'repeat_hours' => (int) env('OCM_ALERT_REPEAT_HOURS', 0),
        'keep_days' => 180,
    ],

    // Eerste beheerder (alleen gebruikt door ocm:bootstrap-admin).
    'admin' => [
        'email' => env('OCM_ADMIN_EMAIL'),
        'password' => env('OCM_ADMIN_PASSWORD'),
        'reset' => (bool) env('OCM_RESET_ADMIN', false),
    ],

    // Policy van de signer: alleen deze commands worden ooit ondertekend (plan §2.4).
    'signer' => [
        'allowed_commands' => [
            'site.info', 'site.inventory', 'events.pull', 'events.ack',
            'update.plugin', 'update.theme', 'update.core', 'update.translations', 'plugin.self_update',
            'backup.create', 'backup.restore', 'db.cleanup', 'license.activate',
            'users.list', 'users.lock', 'users.unlock', 'users.logout', 'users.reset_password', 'users.demote', 'security.two_factor',
        ],
        'max_jobs_per_site_per_minute' => 30,
        'job_lifetime_seconds' => 300,
        'http_timeout_seconds' => 90,
        // Updates (download, kopie voor rollback, health checks) duren langer.
        'command_timeouts' => [
            'update.plugin' => 600,
            'update.theme' => 600,
            'update.core' => 600,
            'update.translations' => 300,
            'plugin.self_update' => 300,
            'backup.create' => 1200,
            'backup.restore' => 1200,
            'db.cleanup' => 600,
            'license.activate' => 120,
        ],
        'max_response_bytes' => 5 * 1024 * 1024,
    ],
];
