<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp;

use NextBuzz\OcMonitor\Client\Admin\Ui;

/**
 * SPF, DKIM en DMARC van het afzenderdomein. De DNS-antwoorden worden per domein een uur bewaard
 * (transient); de beoordeling zelf zit in pure functies (spf(), dmarc(), dkim()) zonder WordPress.
 */
final class DomainCheck
{
    public const ACTION = 'nb_ocm_smtp_domain_check';
    private const TTL = 3600;
    private const MAX_INCLUDES = 10;

    /** Gangbare DKIM-selectors. De echte selector is niet op te vragen, dus we proberen deze. */
    public const SELECTORS = [
        'default', 'google', 'selector1', 'selector2', 'k1', 'k2', 'k3', 's1', 's2', 'mail', 'dkim',
        'smtp', 'mandrill', 'mxvault', 'sendgrid', 'mailjet', 'everlytickey1', 'zoho', 'protonmail', 'fm1', 'fm2',
    ];

    /** Patroon in de SMTP-server => [naam provider, verwachte include(s) in SPF]. */
    private const PROVIDERS = [
        '/office365|outlook/' => ['Microsoft 365', ['spf.protection.outlook.com']],
        '/gmail|google/' => ['Google Workspace', ['_spf.google.com', '_netblocks.google.com']],
        '/sendgrid/' => ['SendGrid', ['sendgrid.net']],
        '/mailgun/' => ['Mailgun', ['mailgun.org']],
        '/brevo|sendinblue/' => ['Brevo', ['spf.brevo.com', 'spf.sendinblue.com']],
        '/amazonses|email-smtp\..*amazonaws/' => ['Amazon SES', ['amazonses.com']],
    ];

    private const VERDICTS = ['ok' => 'In orde', 'warn' => 'Let op', 'crit' => 'Fout'];

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handleRecheck']);
    }

    public static function handleRecheck(): void
    {
        Ui::guard(self::ACTION);
        $domain = self::senderDomain(Options::get(), (string) get_option('admin_email'));
        if ($domain === '') {
            Ui::redirect('smtp', 'error', 'Er is geen geldig afzenderdomein om te controleren.');
        }
        delete_transient(self::cacheKey($domain));
        self::cached($domain);
        Ui::redirect('smtp', 'success', sprintf('Het afzenderdomein %s is opnieuw gecontroleerd.', $domain));
    }

    /** @param array<string, mixed> $o Options::get() */
    public static function renderCard(array $o): void
    {
        $domain = self::senderDomain($o, (string) get_option('admin_email'));
        if ($domain === '') {
            return;
        }
        $data = self::cached($domain);
        $checks = self::evaluate($data, (string) ($o['host'] ?? ''));

        Ui::cardOpen('Afzenderdomein ' . $domain, 'SPF, DKIM en DMARC vertellen ontvangers dat je mail echt van dit domein komt. Ontbreken ze of kloppen ze niet, dan belandt mail sneller in de spam of wordt hij geweigerd.', 'is-wide');
        if (self::emailDomain((string) ($o['from_email'] ?? '')) === '') {
            echo '<p class="nbocm-note nbocm-dns-note">Er is geen afzenderadres ingesteld. Gecontroleerd is het domein van het beheerdersadres van WordPress.</p>';
        }
        echo '<div class="nbocm-dns">';
        foreach ($checks as $c) {
            echo '<div class="nbocm-dns-item">';
            printf('<div class="nbocm-dns-hd"><strong>%s</strong>', esc_html($c['label']));
            Ui::pill($c['verdict'], $c['tone']);
            echo '</div>';
            printf('<p>%s</p>', esc_html($c['text']));
            foreach (array_slice($c['records'], 0, 3) as $record) {
                printf('<code class="nbocm-dns-rec" title="%s">%s</code>', esc_attr($record), esc_html(self::truncate($record)));
            }
            if (count($c['records']) > 3) {
                printf('<small class="nbocm-muted">En nog %d.</small>', count($c['records']) - 3);
            }
            printf('<small class="nbocm-dns-why">%s</small>', esc_html($c['why']));
            echo '</div>';
        }
        echo '</div>';
        Ui::formOpen(self::ACTION);
        echo '<div class="nbocm-actions" style="margin-top:14px">';
        Ui::button('Opnieuw controleren', 'secondary');
        printf('<small class="nbocm-muted">Gecontroleerd op %s. De uitkomst wordt een uur bewaard; een DNS-wijziging kan een paar uur duren.</small>', esc_html(wp_date('j M Y H:i', (int) $data['time'])));
        echo '</div></form>';
        Ui::cardClose();
    }

    // ---- DNS (met cache) ----

    /** @return array<string, mixed> */
    private static function cached(string $domain): array
    {
        $key = self::cacheKey($domain);
        $data = get_transient($key);
        if (is_array($data) && isset($data['time'], $data['available'])) {
            return $data;
        }
        $data = self::lookup($domain);
        set_transient($key, $data, self::TTL);

        return $data;
    }

    private static function cacheKey(string $domain): string
    {
        return 'nb_ocm_smtp_dns_' . md5($domain);
    }

    /**
     * Haalt alle records op die evaluate() nodig heeft. null = DNS-fout bij die vraag.
     *
     * @return array{time: int, available: bool, spf: ?list<string>, includes: array<string, list<string>>, dmarc: ?list<string>, dkim: array<string, string>}
     */
    public static function lookup(string $domain): array
    {
        $out = ['time' => time(), 'available' => function_exists('dns_get_record'), 'spf' => null, 'includes' => [], 'dmarc' => null, 'dkim' => []];
        if (!$out['available']) {
            return $out;
        }
        $txt = self::txt($domain);
        $out['spf'] = $txt === null ? null : self::spfRecords($txt);
        // Eén niveau diep: de includes zelf ophalen, zodat bijv. include:spf.jouwhoster.nl met daarin Microsoft herkend wordt.
        if ($out['spf'] !== null && count($out['spf']) === 1) {
            foreach (array_slice(self::spfIncludes($out['spf'][0]), 0, self::MAX_INCLUDES) as $include) {
                $out['includes'][$include] = self::spfRecords(self::txt($include) ?? []);
            }
        }
        $dmarc = self::txt('_dmarc.' . $domain);
        $out['dmarc'] = $dmarc === null ? null : self::dmarcRecords($dmarc);
        foreach (self::SELECTORS as $selector) {
            $found = self::dkimRecord($selector . '._domainkey.' . $domain);
            if ($found !== null) {
                $out['dkim'][$selector] = $found;
            }
        }

        return $out;
    }

    /** @return list<string>|null TXT-records (gesplitste strings samengevoegd), null bij een DNS-fout */
    private static function txt(string $name): ?array
    {
        $records = @dns_get_record($name, DNS_TXT); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!is_array($records)) {
            return null;
        }
        $out = [];
        foreach ($records as $r) {
            if (($r['type'] ?? '') === 'TXT') {
                $out[] = self::txtValue($r);
            }
        }

        return $out;
    }

    /** DKIM-record op deze naam: de TXT (bijv. "v=DKIM1; k=rsa; p=…") of "CNAME → doel". */
    private static function dkimRecord(string $name): ?string
    {
        $records = @dns_get_record($name, DNS_TXT | DNS_CNAME); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!is_array($records) || $records === []) {
            return null;
        }
        $cname = null;
        foreach ($records as $r) {
            if (($r['type'] ?? '') === 'TXT') {
                $txt = self::txtValue($r);
                if (self::looksLikeDkim($txt)) {
                    return $txt;
                }
            } elseif (($r['type'] ?? '') === 'CNAME' && $cname === null) {
                $cname = 'CNAME → ' . (string) ($r['target'] ?? '');
            }
        }

        return $cname;
    }

    /** @param array<string, mixed> $r */
    private static function txtValue(array $r): string
    {
        return isset($r['entries']) && is_array($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? '');
    }

    // ---- Pure functies (zonder WordPress, getest in tests/run.php) ----

    /** @param array<string, mixed> $o */
    public static function senderDomain(array $o, string $adminEmail): string
    {
        $domain = self::emailDomain((string) ($o['from_email'] ?? ''));

        return $domain !== '' ? $domain : self::emailDomain($adminEmail);
    }

    public static function emailDomain(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return '';
        }
        $domain = strtolower(rtrim(trim(substr($email, $at + 1)), '.'));

        return preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/', $domain) === 1 ? $domain : '';
    }

    /**
     * @param list<string> $txt
     * @return list<string>
     */
    public static function spfRecords(array $txt): array
    {
        return array_values(array_filter(array_map('trim', $txt), static function (string $t): bool {
            return preg_match('/^v=spf1(\s|$)/i', $t) === 1;
        }));
    }

    /**
     * @param list<string> $txt
     * @return list<string>
     */
    public static function dmarcRecords(array $txt): array
    {
        return array_values(array_filter(array_map('trim', $txt), static function (string $t): bool {
            return preg_match('/^v\s*=\s*DMARC1\s*(;|$)/i', $t) === 1;
        }));
    }

    public static function looksLikeDkim(string $txt): bool
    {
        return preg_match('/(^|;)\s*(v=DKIM1|p=|k=(rsa|ed25519))/i', $txt) === 1;
    }

    /** @return list<string> include:- en redirect=-domeinen (kleine letters) */
    public static function spfIncludes(string $record): array
    {
        preg_match_all('/(?:^|\s)(?:[+?~-]?include:|redirect=)(\S+)/i', $record, $m);

        return array_values(array_unique(array_map(static function (string $d): string {
            return strtolower(rtrim($d, '.'));
        }, $m[1])));
    }

    /** @return array{0: string, 1: list<string>}|null [provider, verwachte includes] of null als onbekend */
    public static function provider(string $host): ?array
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }
        foreach (self::PROVIDERS as $pattern => $provider) {
            if (preg_match($pattern, $host) === 1) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @param list<string>|null $records SPF-records op het domein (null = DNS-fout)
     * @param array<string, list<string>> $includes include-domein => zijn SPF-records (één niveau)
     * @return array{tone: string, verdict: string, text: string, records: list<string>}
     */
    public static function spf(?array $records, string $host, array $includes = []): array
    {
        if ($records === null) {
            return self::unknown();
        }
        if ($records === []) {
            return ['tone' => 'crit', 'verdict' => 'Ontbreekt', 'text' => 'Er is geen SPF-record (TXT "v=spf1 …"). Ontvangers weten dan niet welke servers namens dit domein mogen mailen. Voeg er een toe bij je DNS-beheer.', 'records' => []];
        }
        if (count($records) > 1) {
            return ['tone' => 'crit', 'verdict' => 'Meerdere records', 'text' => sprintf('Er staan %d SPF-records. Dat is ongeldig: ontvangers negeren SPF dan helemaal. Voeg ze samen tot één record.', count($records)), 'records' => $records];
        }
        $record = $records[0];
        $tone = 'ok';
        $parts = [];
        if (preg_match('/(?:^|\s)([+?~-]?)all(?:\s|$)/i', $record, $m) === 1) {
            $q = $m[1] === '' ? '+' : $m[1];
            if ($q === '+') {
                $tone = 'warn';
                $parts[] = 'Het record eindigt op +all: elke server ter wereld mag namens dit domein mailen. Dat maakt SPF waardeloos; gebruik ~all of -all.';
            } elseif ($q === '?') {
                $tone = 'warn';
                $parts[] = 'Het record eindigt op ?all (neutraal): ontvangers krijgen geen advies over onbekende servers. Gebruik liever ~all of -all.';
            } else {
                $parts[] = sprintf('Geldig SPF-record dat eindigt op %sall (%s).', $q, $q === '-' ? 'andere servers worden geweigerd' : 'andere servers zijn verdacht');
            }
        } elseif (stripos($record, 'redirect=') !== false) {
            $parts[] = 'Geldig SPF-record dat verwijst naar het SPF van een ander domein (redirect).';
        } else {
            $tone = 'warn';
            $parts[] = 'Het record heeft geen afsluitende all-regel, dus ontvangers krijgen geen advies over onbekende servers. Sluit af met ~all of -all.';
        }

        $provider = self::provider($host);
        if ($provider === null) {
            $parts[] = $host === ''
                ? 'Vul de SMTP-server in om te zien of die in het record staat.'
                : sprintf('Of %s erin staat, zien we niet automatisch: controleer bij je provider welke include of welk IP-adres erbij hoort.', $host);
        } else {
            [$name, $expected] = $provider;
            $direct = self::spfIncludes($record);
            $found = self::includesAny($direct, $expected);
            foreach ($found ? [] : $direct as $include) {
                if (isset($includes[$include]) && count($includes[$include]) === 1 && self::includesAny(self::spfIncludes($includes[$include][0]), $expected)) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $parts[] = sprintf('%s (je SMTP-server) staat erin.', $name);
            } else {
                $tone = $tone === 'ok' ? 'warn' : $tone;
                $parts[] = sprintf('%s (je SMTP-server) staat er niet in. Voeg include:%s toe, anders kan mail via %s als spam worden gezien.', $name, $expected[0], $name);
            }
        }

        return ['tone' => $tone, 'verdict' => self::VERDICTS[$tone], 'text' => implode(' ', $parts), 'records' => [$record]];
    }

    /**
     * @param list<string> $includes
     * @param list<string> $expected
     */
    private static function includesAny(array $includes, array $expected): bool
    {
        foreach ($includes as $include) {
            foreach ($expected as $e) {
                if ($include === $e || substr($include, -strlen('.' . $e)) === '.' . $e) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, string> tags van een DMARC-record, sleutels in kleine letters */
    public static function dmarcTags(string $record): array
    {
        $tags = [];
        foreach (explode(';', $record) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2 && trim($kv[0]) !== '') {
                $tags[strtolower(trim($kv[0]))] = trim($kv[1]);
            }
        }

        return $tags;
    }

    /**
     * @param list<string>|null $records
     * @return array{tone: string, verdict: string, text: string, records: list<string>}
     */
    public static function dmarc(?array $records): array
    {
        if ($records === null) {
            return self::unknown();
        }
        if ($records === []) {
            return ['tone' => 'crit', 'verdict' => 'Ontbreekt', 'text' => 'Er is geen DMARC-record (TXT op _dmarc). Gmail, Yahoo en Microsoft verwachten dat steeds vaker. Begin met "v=DMARC1; p=none; rua=mailto:…" en scherp later aan.', 'records' => []];
        }
        if (count($records) > 1) {
            return ['tone' => 'crit', 'verdict' => 'Meerdere records', 'text' => 'Er staan meerdere DMARC-records. Dat is ongeldig: ontvangers negeren DMARC dan. Houd er één over.', 'records' => $records];
        }
        $tags = self::dmarcTags($records[0]);
        $p = strtolower($tags['p'] ?? '');
        $report = isset($tags['rua']) && $tags['rua'] !== '' ? ' Rapporten gaan naar ' . $tags['rua'] . '.' : '';
        $pct = isset($tags['pct']) && is_numeric($tags['pct']) && (int) $tags['pct'] < 100 ? sprintf(' Het beleid geldt maar voor %d%% van de mail.', (int) $tags['pct']) : '';
        switch ($p) {
            case 'reject':
                return ['tone' => 'ok', 'verdict' => 'p=reject', 'text' => 'Mail die niet door SPF of DKIM komt, wordt geweigerd. De strengste en veiligste instelling.' . $pct . $report, 'records' => $records];
            case 'quarantine':
                return ['tone' => 'ok', 'verdict' => 'p=quarantine', 'text' => 'Mail die niet door SPF of DKIM komt, gaat naar de spammap.' . $pct . $report, 'records' => $records];
            case 'none':
                return ['tone' => 'warn', 'verdict' => 'p=none', 'text' => 'DMARC staat alleen op monitoren: vervalste mail wordt niet tegengehouden. Prima om mee te beginnen; zet hem op quarantine of reject als alles goed gaat.' . $report, 'records' => $records];
            default:
                return ['tone' => 'crit', 'verdict' => 'Ongeldig', 'text' => 'Het DMARC-record heeft geen geldige p= (none, quarantine of reject). Ontvangers negeren het dan.', 'records' => $records];
        }
    }

    /**
     * @param array<string, string> $found selector => record
     * @return array{tone: string, verdict: string, text: string, records: list<string>}
     */
    public static function dkim(array $found, bool $available = true): array
    {
        if (!$available) {
            return self::unknown();
        }
        if ($found === []) {
            return ['tone' => 'warn', 'verdict' => 'Niet gevonden', 'text' => 'Niet gevonden bij gangbare selectors — kan toch ingesteld zijn; controleer bij je mailprovider.', 'records' => []];
        }
        $records = [];
        $revoked = [];
        foreach ($found as $selector => $record) {
            $records[] = $selector . ': ' . $record;
            if (preg_match('/(^|;)\s*p=\s*(;|$)/i', $record) === 1) {
                $revoked[] = (string) $selector;
            }
        }
        if (count($revoked) === count($found)) {
            return ['tone' => 'warn', 'verdict' => 'Ingetrokken', 'text' => 'Gevonden bij selector ' . implode(', ', $revoked) . ', maar de sleutel is leeg (ingetrokken). Controleer de DKIM-instellingen bij je mailprovider.', 'records' => $records];
        }

        return ['tone' => 'ok', 'verdict' => 'Gevonden', 'text' => sprintf('Gevonden bij selector %s. Controleer bij je mailprovider dat de mail ook echt met deze sleutel wordt ondertekend.', implode(', ', array_map('strval', array_keys($found)))), 'records' => $records];
    }

    /**
     * @param array<string, mixed> $data lookup()
     * @return list<array{label: string, tone: string, verdict: string, text: string, records: list<string>, why: string}>
     */
    public static function evaluate(array $data, string $host): array
    {
        $available = (bool) ($data['available'] ?? false);
        $spf = self::spf($available ? ($data['spf'] ?? null) : null, $host, is_array($data['includes'] ?? null) ? $data['includes'] : []);
        $dkim = self::dkim(is_array($data['dkim'] ?? null) ? $data['dkim'] : [], $available);
        $dmarc = self::dmarc($available ? ($data['dmarc'] ?? null) : null);

        return [
            ['label' => 'SPF'] + $spf + ['why' => 'Welke servers namens dit domein mogen mailen. Zonder SPF is je mail makkelijk te vervalsen en gaat hij sneller naar de spam.'],
            ['label' => 'DKIM'] + $dkim + ['why' => 'Een digitale handtekening op elke mail, zodat ontvangers zien dat hij echt van jou komt en onderweg niet is veranderd.'],
            ['label' => 'DMARC'] + $dmarc + ['why' => 'Wat ontvangers moeten doen met mail die niet door SPF of DKIM komt. Grote mailboxen eisen het steeds vaker.'],
        ];
    }

    public static function truncate(string $record, int $max = 90): string
    {
        return mb_strlen($record) > $max ? mb_substr($record, 0, $max - 1) . '…' : $record;
    }

    /** @return array{tone: string, verdict: string, text: string, records: list<string>} */
    private static function unknown(): array
    {
        return ['tone' => 'muted', 'verdict' => 'Niet te controleren', 'text' => 'De DNS-gegevens konden niet worden opgevraagd (DNS-fout of dns_get_record ontbreekt). Probeer het later opnieuw.', 'records' => []];
    }
}
