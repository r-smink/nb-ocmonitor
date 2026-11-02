<?php

return [
    // Publieke panel-sleutel (base64url). De web-app heeft alleen deze nodig (koppelcodes).
    'panel_public_key' => env('OCM_PANEL_PUBLIC_KEY'),

    // Private signing key (base64url). ALLEEN in de signer-container zetten (signer.env), nooit in .env.
    'signing_key' => env('OCM_SIGNING_KEY'),

    // Pad naar de client-plugin-map waaruit de download-zip gebouwd wordt.
    'client_plugin_path' => env('OCM_CLIENT_PLUGIN_PATH', base_path('../client-plugin/nb-ocmonitor-client')),

    // Alleen voor lokaal testen: sites via http toestaan.
    'allow_http_sites' => (bool) env('OCM_ALLOW_HTTP_SITES', false),

    'pairing_lifetime_minutes' => 15,
    'heartbeat_minutes' => (int) env('OCM_HEARTBEAT_MINUTES', 15),

    // Eerste beheerder (alleen gebruikt door ocm:bootstrap-admin).
    'admin' => [
        'email' => env('OCM_ADMIN_EMAIL'),
        'password' => env('OCM_ADMIN_PASSWORD'),
        'reset' => (bool) env('OCM_RESET_ADMIN', false),
    ],

    // Policy van de signer: alleen deze commands worden ooit ondertekend (plan §2.4).
    'signer' => [
        'allowed_commands' => ['site.info', 'site.inventory', 'events.pull', 'events.ack'],
        'max_jobs_per_site_per_minute' => 30,
        'job_lifetime_seconds' => 300,
        'http_timeout_seconds' => 90,
        'max_response_bytes' => 5 * 1024 * 1024,
    ],
];
