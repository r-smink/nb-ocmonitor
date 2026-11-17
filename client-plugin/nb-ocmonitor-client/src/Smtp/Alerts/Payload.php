<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Alerts;

use NextBuzz\OcMonitor\Client\Smtp\Diagnose;

/**
 * Bouwt de inhoud van een melding (tekst, onderwerp, webhook-JSON) uit een emit van AlertState.
 * Zonder WordPress: de sitegegevens worden meegegeven.
 *
 * $site = {title: string, url: string, admin_url: string, panel_url: ?string}
 */
final class Payload
{
    /** @var array<string, string> */
    public const EVENTS = [
        'failed' => 'smtp.failed',
        'backup_used' => 'smtp.backup_used',
        'recovered' => 'smtp.recovered',
        'test' => 'smtp.test',
    ];

    /**
     * @param array<string, mixed> $emit
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    public static function webhook(array $emit, array $site): array
    {
        $r = AlertState::slim((array) ($emit['result'] ?? []));
        $diag = self::diagnose($emit);
        $message = self::message($emit, $site);
        $body = [
            'event' => self::EVENTS[(string) $emit['type']] ?? 'smtp.' . $emit['type'],
            'site_title' => (string) $site['title'],
            'site_url' => (string) $site['url'],
            'message' => $message,
            'text' => $message, // Slack, Mattermost en Google Chat tonen dit veld direct
            'mail' => [
                'to' => $r['to'],
                'subject' => $r['subject'],
                'error' => $r['error'],
                'backup_error' => $r['backup_error'],
                'diagnose_code' => $r['diagnose_code'],
                'diagnose_title' => $diag !== null ? $diag['title'] : '',
                'initiator' => $r['initiator'],
                'log_id' => $r['log_id'],
                'time' => $r['time'] > 0 ? gmdate('c', $r['time']) : null,
            ],
            'suppressed' => (int) ($emit['suppressed'] ?? 0),
        ];
        if (!empty($site['panel_url'])) {
            $body['panel_url'] = (string) $site['panel_url'];
        }

        return $body;
    }

    /**
     * Eén zin voor webhook en e-mail (in de e-mail staat het onderwerp al in de tabel).
     *
     * @param array<string, mixed> $emit
     * @param array<string, mixed> $site
     */
    public static function message(array $emit, array $site, bool $withSubject = true): string
    {
        $name = (string) $site['title'] !== '' ? (string) $site['title'] : (string) $site['url'];
        $r = (array) ($emit['result'] ?? []);
        $diag = self::diagnose($emit);
        $reason = $diag !== null ? lcfirst($diag['title']) : '';
        switch ((string) $emit['type']) {
            case 'recovered':
                $n = (int) ($emit['failures'] ?? 0);

                return sprintf(
                    'E-mail van %s wordt weer verstuurd. Sinds %s %s er %d e-mail%s niet (of alleen via de backup) verstuurd.',
                    $name,
                    self::time((int) ($emit['since'] ?? 0)),
                    $n === 1 ? 'werd' : 'werden',
                    $n,
                    $n === 1 ? '' : 's'
                );
            case 'backup_used':
                $text = sprintf('De SMTP-verbinding van %s werkt niet (%s). De e-mail is via de backupverbinding verstuurd.', $name, $reason);
                break;
            case 'test':
                $text = sprintf('Testmelding van %s: zo ziet een melding over een niet verstuurde e-mail eruit. Er is niets mis.', $name);
                break;
            default:
                $text = sprintf('Een e-mail van %s kon niet worden verstuurd: %s.', $name, $reason);
                $subject = (string) ($r['subject'] ?? '');
                if ($withSubject && $subject !== '' && empty($emit['digest'])) {
                    $text .= sprintf(' Onderwerp: "%s".', mb_substr($subject, 0, 120));
                }
        }

        return $text . self::suppressedNote($emit);
    }

    /** @param array<string, mixed> $emit */
    public static function suppressedNote(array $emit): string
    {
        $n = (int) ($emit['suppressed'] ?? 0);
        if ($n <= 0) {
            return '';
        }
        $since = self::time((int) ($emit['since'] ?? 0));

        return !empty($emit['digest'])
            ? sprintf(' In totaal %d vergelijkbare %s sinds %s (samenvatting; meldingen worden gebundeld).', $n, $n === 1 ? 'fout' : 'fouten', $since)
            : sprintf(' (+%d vergelijkbare %s sinds %s)', $n, $n === 1 ? 'fout' : 'fouten', $since);
    }

    /**
     * @param array<string, mixed> $emit
     * @param array<string, mixed> $site
     */
    public static function subject(array $emit, array $site): string
    {
        $labels = [
            'failed' => 'E-mail niet verstuurd',
            'backup_used' => 'SMTP werkt niet, backup gebruikt',
            'recovered' => 'E-mail werkt weer',
            'test' => 'Testmelding e-mailmeldingen',
        ];
        $label = $labels[(string) $emit['type']] ?? 'Melding';
        $n = (int) ($emit['suppressed'] ?? 0);
        if ($n > 0 && $emit['type'] !== 'recovered') {
            $label .= sprintf(' (+%d)', $n);
        }
        $name = (string) $site['title'] !== '' ? (string) $site['title'] : (string) $site['url'];

        return sprintf('[%s] %s', $name, $label);
    }

    /**
     * @param array<string, mixed> $emit
     * @return array{code: string, title: string, text: string, steps: list<string>}|null
     */
    public static function diagnose(array $emit): ?array
    {
        if ($emit['type'] === 'recovered') {
            return null;
        }
        $r = (array) ($emit['result'] ?? []);
        $error = (string) ($r['error'] ?? '');
        if ($error === '') {
            $error = (string) ($r['backup_error'] ?? '');
        }

        return Diagnose::explain($error);
    }

    /** Link naar de logregel op deze site. */
    public static function logUrl(string $adminUrl, ?int $logId): string
    {
        $args = ['page' => 'nb-ocmonitor', 'tab' => 'maillog'];
        if ($logId !== null && $logId > 0) {
            $args['log'] = $logId;
        }

        return rtrim($adminUrl, '/') . '/admin.php?' . http_build_query($args);
    }

    public static function time(int $time): string
    {
        if ($time <= 0) {
            return 'eerder';
        }

        return function_exists('wp_date') ? (string) wp_date('j M H:i', $time) : gmdate('j M H:i', $time);
    }
}
