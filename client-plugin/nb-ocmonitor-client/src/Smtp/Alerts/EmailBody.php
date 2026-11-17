<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Alerts;

/**
 * HTML van de meldingsmail, in dezelfde Next Buzz-stijl als de testmail (TestMail::body).
 * Zonder WordPress-functies, zodat hij te testen is.
 */
final class EmailBody
{
    /**
     * @param array<string, mixed> $emit
     * @param array<string, mixed> $site {title, url, admin_url, panel_url}
     */
    public static function html(array $emit, array $site): string
    {
        $type = (string) $emit['type'];
        $r = AlertState::slim((array) ($emit['result'] ?? []));
        $diag = Payload::diagnose($emit);
        $headings = [
            'failed' => 'Een e-mail kon niet worden verstuurd',
            'backup_used' => 'SMTP werkt niet, de backup heeft het overgenomen',
            'recovered' => 'E-mail wordt weer verstuurd',
            'test' => 'Testmelding: zo ziet een melding eruit',
        ];
        $accent = $type === 'recovered' ? '#1F9D55' : '#E22830';

        $rows = [];
        if ($type !== 'recovered') {
            if ($r['to'] !== []) {
                $rows['Aan'] = implode(', ', $r['to']);
            }
            if ($r['subject'] !== '') {
                $rows['Onderwerp'] = $r['subject'];
            }
            if ($r['initiator'] !== '') {
                $rows['Verstuurd door'] = $r['initiator'];
            }
            if ($r['time'] > 0) {
                $rows['Tijdstip'] = Payload::time($r['time']);
            }
        }
        $rows['Site'] = (string) $site['url'];
        $table = '';
        foreach ($rows as $label => $value) {
            $table .= '<tr><td style="padding:5px 14px 5px 0;color:#6B7280;vertical-align:top;white-space:nowrap">' . self::e($label) . '</td><td style="padding:5px 0;font-weight:600;word-break:break-word">' . self::e($value) . '</td></tr>';
        }

        $diagHtml = '';
        if ($diag !== null) {
            $steps = '';
            foreach ($diag['steps'] as $step) {
                $steps .= '<li style="margin:0 0 4px">' . self::e($step) . '</li>';
            }
            $diagHtml = '<div style="border-left:4px solid #E22830;background:#FDECEC;border-radius:0 10px 10px 0;padding:12px 16px;margin:16px 0 0">'
                . '<div style="font-weight:700;font-size:15px;margin:0 0 4px">' . self::e($diag['title']) . '</div>'
                . '<div style="margin:0 0 8px">' . self::e($diag['text']) . '</div>'
                . '<ol style="margin:0 0 0 18px;padding:0">' . $steps . '</ol></div>';
            $errors = '';
            if ($r['error'] !== '') {
                $errors .= '<div style="margin-top:12px;font-size:12px;color:#6B7280">Melding van de server</div><div style="font:12px/1.5 Consolas,Menlo,monospace;background:#F7F6F3;border-radius:8px;padding:8px 10px;word-break:break-all">' . self::e($r['error']) . '</div>';
            }
            if ($r['backup_error'] !== '') {
                $errors .= '<div style="margin-top:10px;font-size:12px;color:#6B7280">Melding van de backupverbinding</div><div style="font:12px/1.5 Consolas,Menlo,monospace;background:#F7F6F3;border-radius:8px;padding:8px 10px;word-break:break-all">' . self::e($r['backup_error']) . '</div>';
            }
            $diagHtml .= $errors;
        }

        $link = Payload::logUrl((string) $site['admin_url'], $r['log_id']);
        $button = '<p style="margin:20px 0 0"><a href="' . self::e($link) . '" style="display:inline-block;background:#E22830;color:#fff;text-decoration:none;font-weight:700;border-radius:999px;padding:10px 20px">'
            . ($r['log_id'] !== null && $r['log_id'] > 0 ? 'Bekijk in het e-maillog' : 'Open het e-maillog') . '</a></p>';

        return '<!doctype html><html><body style="margin:0;background:#F7F6F3;font-family:Arial,Helvetica,sans-serif;color:#1F2937">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;overflow:hidden">'
            . '<tr><td style="background:#1F2937;border-bottom:3px solid ' . $accent . ';padding:20px 24px;color:#fff">'
            . '<div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;opacity:.75">NB OC Monitor · ' . self::e((string) $site['title']) . '</div>'
            . '<div style="font-size:20px;font-weight:700;margin-top:4px">' . self::e($headings[$type] ?? 'Melding') . '</div></td></tr>'
            . '<tr><td style="padding:22px 24px;font-size:14px;line-height:1.5">'
            . '<p style="margin:0 0 14px">' . self::e(Payload::message($emit, $site, false)) . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:13px">' . $table . '</table>'
            . $diagHtml . $button
            . '</td></tr><tr><td style="border-top:1px solid #E5E7EB;padding:12px 24px;font-size:12px;color:#6B7280">'
            . 'Je krijgt deze melding omdat dit adres is ingesteld bij NB OC Monitor &gt; Meldingen. Vergelijkbare meldingen worden gebundeld. Beheerd door Next Buzz.'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
