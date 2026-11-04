<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

/**
 * Onderhoudsmodus vanuit het panel (site.maintenance). Bezoekers krijgen een 503 met
 * Retry-After; ingelogde beheerders, WP-admin, inloggen, de REST API en cron werken gewoon.
 * Gaat vanzelf uit na "until" (hoogstens 7 dagen), zodat een vergeten modus de site niet
 * blijvend dichthoudt.
 */
final class MaintenanceMode
{
    private const OPTION = 'nb_ocm_maintenance';

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'maybeBlock'], 0);
    }

    /** @return array{enabled: bool, message: string, until: ?int, since: ?int} */
    public static function status(): array
    {
        $o = get_option(self::OPTION, []);
        $o = is_array($o) ? $o : [];
        $enabled = !empty($o['enabled']) && (empty($o['until']) || (int) $o['until'] > time());

        return [
            'enabled' => $enabled,
            'message' => (string) ($o['message'] ?? ''),
            'until' => $enabled && !empty($o['until']) ? (int) $o['until'] : null,
            'since' => $enabled && !empty($o['since']) ? (int) $o['since'] : null,
        ];
    }

    public static function set(bool $enabled, string $message, ?int $until): void
    {
        if (!$enabled) {
            delete_option(self::OPTION);
            return;
        }
        $current = self::status();
        update_option(self::OPTION, [
            'enabled' => true,
            'message' => $message,
            'until' => $until,
            'since' => $current['enabled'] && $current['since'] ? $current['since'] : time(),
        ], false);
    }

    public static function maybeBlock(): void
    {
        $status = self::status();
        if (!$status['enabled'] || is_admin() || wp_doing_cron() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
            || current_user_can('manage_options')) {
            return;
        }
        $retry = $status['until'] !== null ? max(60, min(3600, $status['until'] - time())) : 3600;
        nocache_headers();
        status_header(503);
        header('Retry-After: ' . $retry);
        $message = $status['message'] !== '' ? $status['message'] : 'We voeren onderhoud uit aan deze website. Probeer het straks opnieuw.';
        $title = get_bloginfo('name');
        echo '<!DOCTYPE html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . esc_html($title) . ' – onderhoud</title>'
            . '<style>body{font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f6f6f4;color:#222}main{max-width:520px;padding:32px;text-align:center}h1{font-size:22px}</style>'
            . '</head><body><main><h1>' . esc_html($title) . '</h1><p>' . nl2br(esc_html($message)) . '</p></main></body></html>';
        exit;
    }
}
