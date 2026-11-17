<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Alerts;

/**
 * Instellingen van de meldingen (optie nb_ocm_smtp_alerts): ontvangers, webhooks, welke
 * gebeurtenissen een melding geven en hoe vaak. De controles zijn los te testen zonder WordPress.
 */
final class AlertSettings
{
    public const OPTION = 'nb_ocm_smtp_alerts';
    public const MAX_TARGETS = 3;
    /** @var list<int> minuten */
    public const THROTTLES = [5, 15, 60, 240];
    /** @var array<string, string> type => label */
    public const TYPES = [
        'failed' => 'E-mail niet verstuurd',
        'backup_used' => 'Backupverbinding gebruikt',
        'recovered' => 'E-mail werkt weer',
    ];

    public const DEFAULTS = [
        'emails' => [],
        'webhooks' => [],
        'events' => ['failed' => true, 'backup_used' => true, 'recovered' => true],
        'throttle' => 15,
    ];

    /** @return array{emails: list<string>, webhooks: list<string>, events: array<string, bool>, throttle: int} */
    public static function get(): array
    {
        $raw = get_option(self::OPTION);

        return self::normalize(is_array($raw) ? $raw : []);
    }

    /**
     * Slaat de invoer van het formulier op.
     *
     * @param array<string, mixed> $input
     * @return array{settings: array<string, mixed>, invalid: list<string>}
     */
    public static function save(array $input): array
    {
        $emails = self::parseEmails((array) ($input['emails'] ?? []));
        $webhooks = self::parseUrls((array) ($input['webhooks'] ?? []));
        $events = [];
        foreach (array_keys(self::TYPES) as $type) {
            $events[$type] = !empty($input['events'][$type]);
        }
        $settings = self::normalize([
            'emails' => $emails['valid'],
            'webhooks' => $webhooks['valid'],
            'events' => $events,
            'throttle' => $input['throttle'] ?? 15,
        ]);
        update_option(self::OPTION, $settings, false);

        return ['settings' => $settings, 'invalid' => array_merge($emails['invalid'], $webhooks['invalid'])];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{emails: list<string>, webhooks: list<string>, events: array<string, bool>, throttle: int}
     */
    public static function normalize(array $raw): array
    {
        $events = [];
        foreach (self::DEFAULTS['events'] as $type => $default) {
            $events[$type] = isset($raw['events'][$type]) ? (bool) $raw['events'][$type] : $default;
        }
        $throttle = (int) ($raw['throttle'] ?? self::DEFAULTS['throttle']);

        return [
            'emails' => self::parseEmails((array) ($raw['emails'] ?? []))['valid'],
            'webhooks' => self::parseUrls((array) ($raw['webhooks'] ?? []))['valid'],
            'events' => $events,
            'throttle' => in_array($throttle, self::THROTTLES, true) ? $throttle : self::DEFAULTS['throttle'],
        ];
    }

    /**
     * Geldige, unieke adressen (maximaal 3). Lege velden tellen niet mee.
     *
     * @param array<mixed> $list
     * @return array{valid: list<string>, invalid: list<string>}
     */
    public static function parseEmails(array $list): array
    {
        $valid = [];
        $invalid = [];
        foreach ($list as $item) {
            $email = trim((string) (is_scalar($item) ? $item : ''));
            if ($email === '') {
                continue;
            }
            if (!self::isEmail($email)) {
                $invalid[] = $email;
                continue;
            }
            if (!in_array(strtolower($email), array_map('strtolower', $valid), true)) {
                $valid[] = $email;
            }
        }
        foreach (array_slice($valid, self::MAX_TARGETS) as $extra) {
            $invalid[] = $extra;
        }

        return ['valid' => array_slice($valid, 0, self::MAX_TARGETS), 'invalid' => $invalid];
    }

    /**
     * Alleen https-adressen (maximaal 3). Interne adressen (localhost, 10.x enz.) houdt
     * wp_safe_remote_post bij het versturen tegen.
     *
     * @param array<mixed> $list
     * @return array{valid: list<string>, invalid: list<string>}
     */
    public static function parseUrls(array $list): array
    {
        $valid = [];
        $invalid = [];
        foreach ($list as $item) {
            $url = trim((string) (is_scalar($item) ? $item : ''));
            if ($url === '') {
                continue;
            }
            if (!self::isWebhookUrl($url)) {
                $invalid[] = $url;
                continue;
            }
            if (!in_array($url, $valid, true)) {
                $valid[] = $url;
            }
        }
        foreach (array_slice($valid, self::MAX_TARGETS) as $extra) {
            $invalid[] = $extra;
        }

        return ['valid' => array_slice($valid, 0, self::MAX_TARGETS), 'invalid' => $invalid];
    }

    public static function isEmail(string $email): bool
    {
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return !function_exists('is_email') || is_email($email) !== false;
    }

    public static function isWebhookUrl(string $url): bool
    {
        if (strlen($url) > 500 || preg_match('/\s/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && (string) ($parts['host'] ?? '') !== '';
    }

    /** Hostnaam van een webhook, voor de geschiedenis (het pad bevat vaak een geheim token). */
    public static function webhookLabel(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: 'webhook');
    }
}
