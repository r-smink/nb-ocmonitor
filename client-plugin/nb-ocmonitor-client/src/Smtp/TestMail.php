<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp;

/**
 * Verstuurt een testmail via wp_mail() met de actieve instellingen en vangt de SMTP-dialoog op,
 * met de inloggegevens eruit gefilterd.
 */
final class TestMail
{
    public const HEADER = 'X-NB-OCM-Test: 1';

    /**
     * @param array<string, mixed> $o
     * @return array{ok: bool, to: string, error: string, debug: string, diagnose: ?array<string, mixed>, time: int}
     */
    public static function send(string $to, array $o, ?string $password): array
    {
        $lines = [];
        $error = '';
        Mailer::captureDebug(static function (string $line) use (&$lines): void {
            if (count($lines) < 400) {
                $lines[] = $line;
            }
        });
        $onFail = static function ($wpError) use (&$error): void {
            $error = is_wp_error($wpError) ? $wpError->get_error_message() : 'Onbekende fout';
        };
        add_action('wp_mail_failed', $onFail);
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        try {
            $ok = wp_mail(
                $to,
                sprintf('Testmail van %s', $site !== '' ? $site : home_url()),
                self::body($site, $o),
                ['Content-Type: text/html; charset=UTF-8', self::HEADER]
            );
        } finally {
            remove_action('wp_mail_failed', $onFail);
            Mailer::captureDebug(null);
        }
        $debug = self::redact(implode("\n", $lines), (string) $o['user'], (string) $password);
        if (!$ok && $error === '') {
            $error = 'wp_mail() gaf false terug zonder foutmelding.';
        }

        return [
            'ok' => (bool) $ok,
            'to' => $to,
            'error' => $error,
            'debug' => $debug,
            'diagnose' => $ok ? null : Diagnose::explain($error, $debug),
            'time' => time(),
        ];
    }

    /** Gebruikersnaam en wachtwoord (ook base64, zoals bij AUTH LOGIN/PLAIN) uit de dialoog halen. */
    public static function redact(string $debug, string $user, string $password): string
    {
        $secrets = [];
        if ($password !== '') {
            $secrets[] = $password;
            $secrets[] = base64_encode($password);
            $secrets[] = base64_encode("\0" . $user . "\0" . $password);
            $secrets[] = base64_encode($user . "\0" . $user . "\0" . $password);
        }
        if ($user !== '') {
            $secrets[] = base64_encode($user);
        }
        foreach ($secrets as $secret) {
            $debug = str_replace($secret, '[verborgen]', $debug);
        }
        // AUTH PLAIN/XOAUTH2 met het token op dezelfde regel.
        return (string) preg_replace('/(CLIENT -> SERVER:\s*AUTH\s+(?:PLAIN|XOAUTH2|CRAM-MD5)\s+)\S+/i', '$1[verborgen]', $debug);
    }

    /** @param array<string, mixed> $o */
    private static function body(string $site, array $o): string
    {
        $rows = [
            'Site' => home_url(),
            'Server' => $o['host'] . ':' . $o['port'],
            'Versleuteling' => Options::ENCRYPTIONS[$o['encryption']] ?? $o['encryption'],
            'Verstuurd op' => wp_date('j F Y H:i:s'),
        ];
        $table = '';
        foreach ($rows as $label => $value) {
            $table .= '<tr><td style="padding:6px 14px 6px 0;color:#6B7280">' . esc_html($label) . '</td><td style="padding:6px 0;font-weight:600">' . esc_html((string) $value) . '</td></tr>';
        }

        return '<!doctype html><html><body style="margin:0;background:#F7F6F3;font-family:Arial,Helvetica,sans-serif;color:#1F2937">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;overflow:hidden">'
            . '<tr><td style="background:#1F2937;border-bottom:3px solid #E22830;padding:20px 24px;color:#fff">'
            . '<div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;opacity:.75">NB OC Monitor</div>'
            . '<div style="font-size:20px;font-weight:700;margin-top:4px">De SMTP-instellingen werken</div></td></tr>'
            . '<tr><td style="padding:22px 24px;font-size:14px;line-height:1.5">'
            . '<p style="margin:0 0 14px">Deze testmail is via SMTP verstuurd door ' . esc_html($site) . '. Komt hij aan, dan kan de site mail versturen.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:13px">' . $table . '</table>'
            . '</td></tr><tr><td style="border-top:1px solid #E5E7EB;padding:12px 24px;font-size:12px;color:#6B7280">Beheerd door Next Buzz</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
