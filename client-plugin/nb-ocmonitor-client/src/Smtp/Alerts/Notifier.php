<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Alerts;

use NextBuzz\OcMonitor\Client\Smtp\Mailer;

/** Verstuurt een melding naar de ingestelde e-mailadressen en webhooks en houdt het resultaat bij. */
final class Notifier
{
    public const HEADER = 'X-NB-OCM-Alert: 1';

    /**
     * @param array<string, mixed> $emit zie AlertState
     * @param array<string, mixed> $settings AlertSettings::get()
     * @param bool $bypass via de standaard mail() van WordPress in plaats van de (kapotte) SMTP-verbinding
     * @return list<array{time: int, type: string, channel: string, target: string, ok: bool, error: string}>
     */
    public static function deliver(array $emit, array $settings, bool $bypass): array
    {
        $site = self::site();
        $type = (string) $emit['type'];
        $results = [];
        if ($settings['emails'] !== []) {
            $subject = Payload::subject($emit, $site);
            $html = EmailBody::html($emit, $site);
            foreach ($settings['emails'] as $email) {
                [$ok, $error] = self::email((string) $email, $subject, $html, $bypass);
                $results[] = ['time' => time(), 'type' => $type, 'channel' => 'email', 'target' => (string) $email, 'ok' => $ok, 'error' => $error];
            }
        }
        if ($settings['webhooks'] !== []) {
            $body = (string) wp_json_encode(Payload::webhook($emit, $site));
            foreach ($settings['webhooks'] as $url) {
                [$ok, $error] = self::webhook((string) $url, $body);
                $results[] = ['time' => time(), 'type' => $type, 'channel' => 'webhook', 'target' => AlertSettings::webhookLabel((string) $url), 'ok' => $ok, 'error' => $error];
            }
        }
        if ($results !== []) {
            History::add($results);
        }

        return $results;
    }

    /** @return array{0: bool, 1: string} */
    private static function email(string $to, string $subject, string $html, bool $bypass): array
    {
        $error = '';
        $onFail = static function ($wpError) use (&$error): void {
            $error = is_wp_error($wpError) ? $wpError->get_error_message() : 'Onbekende fout';
        };
        add_action('wp_mail_failed', $onFail);
        Mailer::bypass($bypass);
        try {
            $ok = (bool) wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8', self::HEADER]);
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        } finally {
            Mailer::bypass(false);
            remove_action('wp_mail_failed', $onFail);
        }
        if (!$ok && $error === '') {
            $error = 'wp_mail() gaf false terug zonder foutmelding.';
        }

        return [$ok, $ok ? '' : ($bypass ? 'Via PHP mail(): ' : '') . $error];
    }

    /** @return array{0: bool, 1: string} */
    private static function webhook(string $url, string $body): array
    {
        if (!AlertSettings::isWebhookUrl($url)) {
            return [false, 'Alleen https-adressen zijn toegestaan.'];
        }
        $response = wp_safe_remote_post($url, [
            'timeout' => 5,
            'redirection' => 0,
            'user-agent' => 'nb-ocmonitor-smtp/' . NB_OCM_VERSION,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
            'data_format' => 'body',
        ]);
        if (is_wp_error($response)) {
            return [false, $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);

        return $code >= 200 && $code < 300 ? [true, ''] : [false, sprintf('HTTP %d', $code)];
    }

    /** @return array{title: string, url: string, admin_url: string, panel_url: ?string} */
    public static function site(): array
    {
        $config = get_option('nb_ocm_config');
        $panel = is_array($config) && !empty($config['panel_url']) ? (string) $config['panel_url'] : null;

        return [
            'title' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'url' => (string) home_url(),
            'admin_url' => (string) admin_url(),
            'panel_url' => $panel,
        ];
    }
}
