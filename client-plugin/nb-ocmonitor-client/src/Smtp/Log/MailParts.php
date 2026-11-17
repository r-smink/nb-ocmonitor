<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Log;

/**
 * Ontleden van wp_mail-argumenten (ontvangers, headers, bijlagen), zonder WordPress.
 * Zo werkt WordPress zelf ook: headers als string (regels) of array, adressen met komma's gescheiden.
 */
final class MailParts
{
    public const HEADER_TEST = 'x-nb-ocm-test';
    public const HEADER_ALERT = 'x-nb-ocm-alert';
    public const HEADER_CONNECTION = 'x-nb-ocm-connection';

    /**
     * Adressen zoals opgegeven ("Naam <a@b.nl>" of "a@b.nl"), zonder lege. Komma's binnen
     * aanhalingstekens ("Jansen, Piet" <p@x.nl>) splitsen niet.
     *
     * @param mixed $value string met komma's, of een array daarvan
     * @return list<string>
     */
    public static function addresses($value): array
    {
        $parts = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = self::oneLine((string) $item);
            foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $item) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
        }

        return $parts;
    }

    /** Alleen het e-mailadres uit "Naam <a@b.nl>". */
    public static function email(string $address): string
    {
        if (preg_match('/<([^<>]*)>\s*$/', $address, $m) === 1) {
            return trim($m[1]);
        }

        return trim($address, " \t\"'");
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    public static function emails($value): array
    {
        $out = [];
        foreach (self::addresses($value) as $address) {
            $email = self::email($address);
            if ($email !== '') {
                $out[] = $email;
            }
        }

        return $out;
    }

    /**
     * Headerregels. Een string wordt per regel gesplitst; vervolgregels (beginnend met spatie of tab) worden samengevoegd.
     *
     * @param mixed $headers
     * @return list<string>
     */
    public static function headerLines($headers): array
    {
        if (is_array($headers)) {
            $lines = [];
            foreach ($headers as $line) {
                if (is_scalar($line) && trim((string) $line) !== '') {
                    $lines[] = trim(self::oneLine((string) $line));
                }
            }

            return $lines;
        }
        if (!is_scalar($headers)) {
            return [];
        }
        $text = str_replace(["\r\n", "\r"], "\n", (string) $headers);
        $text = (string) preg_replace('/\n[ \t]+/', ' ', $text);
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            if (trim($line) !== '') {
                $lines[] = trim($line);
            }
        }

        return $lines;
    }

    /**
     * De headers die de log nodig heeft.
     *
     * @param mixed $headers
     * @return array{lines: list<string>, cc: list<string>, bcc: list<string>, from: string, content_type: string, test: bool, alert: bool, connection: string}
     */
    public static function parseHeaders($headers): array
    {
        $out = ['lines' => self::headerLines($headers), 'cc' => [], 'bcc' => [], 'from' => '', 'content_type' => '', 'test' => false, 'alert' => false, 'connection' => ''];
        foreach ($out['lines'] as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            switch ($name) {
                case 'cc':
                    $out['cc'] = array_merge($out['cc'], self::addresses($value));
                    break;
                case 'bcc':
                    $out['bcc'] = array_merge($out['bcc'], self::addresses($value));
                    break;
                case 'from':
                    $out['from'] = $value;
                    break;
                case 'content-type':
                    $out['content_type'] = strtolower(trim(explode(';', $value)[0]));
                    break;
                case self::HEADER_TEST:
                    $out['test'] = $value === '1';
                    break;
                case self::HEADER_ALERT:
                    $out['alert'] = $value === '1';
                    break;
                case self::HEADER_CONNECTION:
                    $out['connection'] = strtolower($value);
                    break;
            }
        }

        return $out;
    }

    /**
     * Headers voor opnieuw versturen: zonder de eigen X-NB-OCM-headers en eventueel zonder Cc/Bcc.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    public static function resendHeaders(array $lines, bool $dropRecipients): array
    {
        $out = [];
        foreach ($lines as $line) {
            $name = strtolower(trim((string) strstr($line, ':', true)));
            if (strpos($name, 'x-nb-ocm-') === 0 || ($dropRecipients && ($name === 'cc' || $name === 'bcc'))) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Bestandsnamen van de bijlagen (alleen de naam, het pad en de inhoud bewaren we niet).
     *
     * @param mixed $attachments
     * @return list<string>
     */
    public static function attachmentNames($attachments): array
    {
        if (is_string($attachments)) {
            $attachments = $attachments === '' ? [] : explode("\n", str_replace("\r\n", "\n", $attachments));
        }
        $names = [];
        foreach (is_array($attachments) ? $attachments : [] as $key => $path) {
            if (!is_scalar($path) || trim((string) $path) === '') {
                continue;
            }
            // Sinds WP 6.x mag de key een eigen bestandsnaam zijn.
            $name = is_string($key) && $key !== '' ? $key : basename(str_replace('\\', '/', trim((string) $path)));
            $names[] = self::clip(self::oneLine($name), 191);
        }

        return $names;
    }

    /** Ingekort tot $max tekens (UTF-8-veilig). */
    public static function clip(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    public static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
    }

    /**
     * Een veilig document voor de voorbeeldweergave (iframe srcdoc met sandbox): externe bronnen
     * (trackingpixels, scripts, lettertypen) worden via een CSP geblokkeerd. Platte tekst wordt ge-escapet.
     */
    public static function previewDocument(string $content, string $contentType): string
    {
        $csp = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:; font-src data:">';
        if ($contentType !== 'text/html') {
            return '<!doctype html><html><head><meta charset="utf-8">' . $csp . '</head><body style="margin:0;padding:14px;font:13px/1.55 ui-monospace,Consolas,monospace;color:#1F2937;white-space:pre-wrap;word-break:break-word">'
                . htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</body></html>';
        }
        if (preg_match('/<head\b[^>]*>/i', $content, $m, PREG_OFFSET_CAPTURE) === 1) {
            $at = (int) $m[0][1] + strlen($m[0][0]);

            return substr($content, 0, $at) . $csp . substr($content, $at);
        }

        return $csp . $content;
    }
}
