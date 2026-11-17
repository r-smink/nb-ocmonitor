<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Log;

/** CSV- en EML-export van de e-maillog. Pure functies, zonder WordPress. */
final class Export
{
    public const CSV_DELIMITER = ';'; // Excel met Nederlandse instellingen verwacht een puntkomma

    /** @return list<string> */
    public static function csvColumns(): array
    {
        return ['ID', 'Datum', 'Status', 'Verbinding', 'Aan', 'Cc', 'Van', 'Onderwerp', 'Bron', 'Bronbestand', 'Fout', 'Fout backup', 'Diagnose', 'Test', 'Message-ID', 'Opnieuw verstuurd van'];
    }

    /**
     * @param array<string, mixed> $row een rij uit de tabel
     * @return list<string>
     */
    public static function csvValues(array $row, string $date): array
    {
        return [
            (string) ($row['id'] ?? ''),
            $date,
            self::statusLabel((string) ($row['status'] ?? ''), (string) ($row['connection'] ?? '')),
            self::connectionLabel((string) ($row['connection'] ?? '')),
            (string) ($row['to_email'] ?? ''),
            (string) ($row['cc'] ?? ''),
            (string) ($row['from_email'] ?? ''),
            (string) ($row['subject'] ?? ''),
            (string) ($row['initiator'] ?? ''),
            (string) ($row['initiator_file'] ?? ''),
            (string) ($row['error'] ?? ''),
            (string) ($row['backup_error'] ?? ''),
            (string) ($row['diagnose_code'] ?? ''),
            !empty($row['is_test']) ? 'ja' : 'nee',
            (string) ($row['message_id'] ?? ''),
            !empty($row['resent_of']) ? (string) $row['resent_of'] : '',
        ];
    }

    /**
     * Eén CSV-cel. Begint de waarde met =, +, -, @, tab of CR, dan komt er een ' voor, zodat
     * Excel of Calc er geen formule van maakt (CSV-injectie).
     */
    public static function csvCell(string $value): string
    {
        if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
            $value = "'" . $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    /** @param list<string> $values */
    public static function csvLine(array $values, string $delimiter = self::CSV_DELIMITER): string
    {
        return implode($delimiter, array_map([self::class, 'csvCell'], $values)) . "\r\n";
    }

    public static function statusLabel(string $status, string $connection): string
    {
        if ($status === 'sent') {
            return $connection === 'backup' ? 'Via backup' : 'Verzonden';
        }

        return ['failed' => 'Mislukt', 'pending' => 'Bezig'][$status] ?? $status;
    }

    public static function connectionLabel(string $connection): string
    {
        return ['primary' => 'SMTP', 'backup' => 'Backup', 'wordpress' => 'WordPress'][$connection] ?? '';
    }

    /**
     * Het bericht als .eml (RFC 5322). Zonder Bcc. Zonder gelogde inhoud staat er een melding in de body.
     *
     * @param array<string, mixed> $row
     */
    public static function eml(array $row): string
    {
        $headers = [];
        $headers[] = 'Date: ' . gmdate('D, d M Y H:i:s', (int) ($row['created_at'] ?? 0)) . ' +0000';
        $from = self::headerValue((string) ($row['from_email'] ?? ''));
        if ($from !== '') {
            $headers[] = 'From: ' . self::addressList([$from]);
        }
        $headers[] = 'To: ' . self::addressList(MailParts::addresses((string) ($row['to_email'] ?? '')));
        $cc = MailParts::addresses((string) ($row['cc'] ?? ''));
        if ($cc !== []) {
            $headers[] = 'Cc: ' . self::addressList($cc);
        }
        $headers[] = 'Subject: ' . self::encodeHeader(self::headerValue((string) ($row['subject'] ?? '')));
        $messageId = self::headerValue((string) ($row['message_id'] ?? ''));
        if ($messageId !== '') {
            $headers[] = 'Message-ID: ' . $messageId;
        }
        $headers[] = 'X-NB-OCM-Log-Id: ' . (int) ($row['id'] ?? 0);
        $headers[] = 'MIME-Version: 1.0';

        $content = $row['content'] ?? null;
        $html = ($row['content_type'] ?? '') === 'text/html';
        if (!is_string($content)) {
            $content = 'De inhoud van deze e-mail is niet gelogd. Zet "Inhoud bewaren" aan in de instellingen van de e-maillog.';
            $html = false;
        }
        $headers[] = 'Content-Type: ' . ($html ? 'text/html' : 'text/plain') . '; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';

        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($content), 76, "\r\n")) . "\r\n";
    }

    /** Geen regeleinden in een header (header-injectie). */
    public static function headerValue(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }

    /**
     * Niet-ASCII (bijv. "Bestelling ontvangen – €12") als encoded-words (RFC 2047), in stukken van
     * hooguit 45 bytes zonder een UTF-8-teken te splitsen.
     */
    public static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }
        $chunks = [];
        $current = '';
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($value) as $char) {
            if (strlen($current . $char) > 45) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return implode("\r\n ", array_map(static function (string $chunk): string {
            return '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }, $chunks));
    }

    /** @param list<string> $addresses */
    private static function addressList(array $addresses): string
    {
        $out = [];
        foreach ($addresses as $address) {
            $address = self::headerValue($address);
            if (preg_match('/^(.*?)\s*<([^<>]+)>$/', $address, $m) === 1 && trim($m[1], " \"") !== '') {
                $out[] = self::encodeHeader(trim($m[1])) . ' <' . $m[2] . '>';
            } else {
                $out[] = $address;
            }
        }

        return implode(', ', $out);
    }
}
