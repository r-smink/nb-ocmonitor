<?php

namespace App\Ocm;

use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NextBuzz\OcMonitor\Protocol\Canonical;

/**
 * Klanten, contactgegevens en websites in één keer inlezen uit CSV (of geplakt uit Excel).
 * Sites worden aangemaakt zonder koppelcode; die maak je later per site aan. Bestaande klanten
 * (zelfde naam) worden aangevuld: alleen lege velden worden ingevuld, niets wordt overschreven.
 */
class CustomerImport
{
    public const MAX_BYTES = 1048576;

    public const MAX_ROWS = 2000;

    /** Kolom => herkende kopnamen (kleine letters, alleen letters en cijfers). */
    public const COLUMNS = [
        'name' => ['klant', 'klantnaam', 'bedrijf', 'bedrijfsnaam', 'naam', 'customer', 'company', 'organisatie'],
        'contact_name' => ['contactpersoon', 'contact', 'naamcontactpersoon', 'tav', 'contactname'],
        'email' => ['email', 'emailadres', 'mail', 'mailadres'],
        'phone' => ['telefoon', 'telefoonnummer', 'tel', 'phone', 'mobiel'],
        'report_cc' => ['cc', 'rapportcc', 'extraemail', 'kopie'],
        'website' => ['website', 'websites', 'site', 'sites', 'url', 'urls', 'domein', 'domeinen', 'domeinnaam'],
        'visits_per_year' => ['onderhoud', 'contract', 'beurten', 'beurtenperjaar', 'onderhoudperjaar', 'frequentie'],
        'contract_start' => ['startdatum', 'start', 'contractstart', 'eersteonderhoud', 'datum'],
        'notes' => ['notities', 'notitie', 'opmerkingen', 'opmerking', 'notes'],
    ];

    /** Kolommen in het voorbeeldbestand. */
    public const TEMPLATE = ['Klant', 'Contactpersoon', 'E-mail', 'Telefoon', 'CC', 'Website', 'Onderhoud', 'Startdatum', 'Notities'];

    /**
     * Leest de tekst en vergelijkt hem met wat er al in het panel staat.
     *
     * @return array{customers: list<array>, errors: list<string>, counts: array<string, int>}
     */
    public static function plan(string $text): array
    {
        $errors = [];
        $rows = self::rows($text, $errors);
        $customers = [];
        $seenHosts = [];
        $existing = Customer::query()->get()->keyBy(fn (Customer $c) => self::key($c->name));
        $sites = Site::query()->get(['id', 'host', 'customer_id', 'name'])->keyBy('host');

        foreach ($rows as $line => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                if (trim(implode('', $row)) !== '') {
                    $errors[] = "Regel {$line}: geen klantnaam, overgeslagen.";
                }
                continue;
            }
            $name = Str::limit($name, 120, '');
            $key = self::key($name);
            if (!isset($customers[$key])) {
                $model = $existing->get($key);
                $customers[$key] = [
                    'name' => $model?->name ?? $name,
                    'id' => $model?->id,
                    'action' => $model ? 'update' : 'create',
                    'fields' => [],
                    'sites' => [],
                    'warnings' => [],
                ];
            }
            $c = &$customers[$key];
            foreach (self::fields($row, $line, $c['warnings']) as $field => $value) {
                $c['fields'][$field] ??= $value;
            }
            foreach (preg_split('/[\s,;|]+/', (string) ($row['website'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $raw) {
                $site = self::site($raw);
                if ($site === null) {
                    $c['warnings'][] = "Regel {$line}: \"{$raw}\" is geen geldige website.";
                    continue;
                }
                if (isset($seenHosts[$site['host']])) {
                    continue;
                }
                $seenHosts[$site['host']] = true;
                $found = $sites->get($site['host']) ?? $sites->get(self::otherWww($site['host']));
                if ($found === null) {
                    $site['action'] = 'create';
                } elseif ($found->customer_id === null) {
                    $site += ['action' => 'attach', 'id' => $found->id];
                } elseif ($c['id'] !== null && $found->customer_id === $c['id']) {
                    $site['action'] = 'exists';
                } else {
                    $site['action'] = 'other';
                }
                $c['sites'][] = $site;
            }
            unset($c);
        }

        if ($rows !== [] && $customers === []) {
            $errors[] = 'Geen klanten gevonden. Staat er een kolom "Klant" in de eerste regel?';
        }
        $list = array_values($customers);
        usort($list, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        $sitesFlat = array_merge([], ...array_map(fn ($c) => $c['sites'], $list));

        return [
            'customers' => $list,
            'errors' => $errors,
            'counts' => [
                'create' => count(array_filter($list, fn ($c) => $c['action'] === 'create')),
                'update' => count(array_filter($list, fn ($c) => $c['action'] === 'update')),
                'sites' => count(array_filter($sitesFlat, fn ($s) => $s['action'] === 'create')),
                'attach' => count(array_filter($sitesFlat, fn ($s) => $s['action'] === 'attach')),
                'skipped' => count(array_filter($sitesFlat, fn ($s) => in_array($s['action'], ['exists', 'other'], true))),
            ],
        ];
    }

    /** @return array{customers: int, sites: int} */
    public static function apply(array $plan, User $user): array
    {
        $made = ['customers' => 0, 'sites' => 0];
        DB::transaction(function () use ($plan, &$made) {
            foreach ($plan['customers'] as $row) {
                if ($row['action'] === 'create') {
                    $customer = Customer::query()->create(array_merge(['email' => '', 'visits_per_year' => 0], $row['fields'], ['name' => $row['name']]));
                    $made['customers']++;
                } else {
                    $customer = Customer::query()->find($row['id']);
                    if ($customer === null) {
                        continue;
                    }
                    // Alleen lege velden aanvullen.
                    foreach ($row['fields'] as $field => $value) {
                        $current = $customer->getAttribute($field);
                        if ($current === null || $current === '' || ($field === 'visits_per_year' && (int) $current === 0)) {
                            $customer->setAttribute($field, $value);
                        }
                    }
                    $customer->save();
                }
                foreach ($row['sites'] as $s) {
                    if ($s['action'] === 'create' && !Site::query()->where('host', $s['host'])->exists()) {
                        $site = new Site([
                            'site_id' => 'site_' . Str::lower(Str::random(20)),
                            'name' => $s['name'],
                            'customer_id' => $customer->id,
                            'client_name' => $customer->name,
                            'url' => $s['url'],
                            'host' => $s['host'],
                        ]);
                        $site->scopes = Site::DEFAULT_SCOPES;
                        $site->save();
                        $made['sites']++;
                    } elseif ($s['action'] === 'attach') {
                        Site::query()->whereKey($s['id'])->whereNull('customer_id')->update(['customer_id' => $customer->id, 'client_name' => $customer->name]);
                    }
                }
            }
        });
        Activity::log('customers_imported', $made + ['updated' => $plan['counts']['update']], null, $user);

        return $made;
    }

    public static function template(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::TEMPLATE, ';', '"', '');
        fputcsv($out, ['Bakkerij De Korst BV', 'Anna de Vries', 'anna@dekorst.nl', '06-12345678', '', 'bakkerijdekorst.nl', '4', '', ''], ';', '"', '');
        fputcsv($out, ['Bakkerij De Korst BV', '', '', '', '', 'dekorst-webshop.nl', '', '', 'tweede site op een eigen regel'], ';', '"', '');
        fputcsv($out, ['Fietsenmaker Jansen', 'Piet Jansen', 'piet@jansenfietsen.nl', '', 'boekhouding@jansenfietsen.nl', 'www.jansenfietsen.nl', '2x per jaar', '15-01-2027', ''], ';', '"', '');
        rewind($out);

        return "\u{FEFF}" . stream_get_contents($out);
    }

    /**
     * @param list<string> $errors
     * @return array<int, array<string, string>> regelnummer => kolom => waarde
     */
    private static function rows(string $text, array &$errors): array
    {
        $text = preg_replace('/^\x{FEFF}/u', '', $text) ?? $text;
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') {
            return [];
        }
        $first = strtok($text, "\n");
        $delimiter = collect(["\t", ';', ','])->sortByDesc(fn ($d) => substr_count((string) $first, $d))->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        $header = fgetcsv($stream, null, $delimiter, '"', '');
        $map = [];
        foreach ($header ?: [] as $i => $label) {
            $norm = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii((string) $label)));
            foreach (self::COLUMNS as $column => $aliases) {
                if (in_array($norm, $aliases, true) && !in_array($column, $map, true)) {
                    $map[$i] = $column;
                }
            }
        }
        if (!in_array('name', $map, true)) {
            $errors[] = 'De eerste regel moet de kolomnamen bevatten, met in elk geval "Klant". Download het voorbeeldbestand voor de juiste kolommen.';

            return [];
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = 'Meer dan ' . self::MAX_ROWS . ' regels; de rest is niet ingelezen.';
                break;
            }
            $row = [];
            foreach ($map as $i => $column) {
                $row[$column] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[$line] = $row;
        }

        return $rows;
    }

    /**
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    private static function fields(array $row, int $line, array &$warnings): array
    {
        $out = [];
        foreach (['contact_name' => 120, 'phone' => 40, 'notes' => 5000] as $field => $max) {
            if (($row[$field] ?? '') !== '') {
                $out[$field] = Str::limit($row[$field], $max, '');
            }
        }
        if (($row['email'] ?? '') !== '') {
            if (filter_var($row['email'], FILTER_VALIDATE_EMAIL) && mb_strlen($row['email']) <= 190) {
                $out['email'] = Str::lower($row['email']);
            } else {
                $warnings[] = "Regel {$line}: ongeldig e-mailadres \"{$row['email']}\".";
            }
        }
        if (($row['report_cc'] ?? '') !== '') {
            $cc = [];
            foreach (preg_split('/[\s,;]+/', $row['report_cc'], -1, PREG_SPLIT_NO_EMPTY) as $email) {
                filter_var($email, FILTER_VALIDATE_EMAIL) ? $cc[] = Str::lower($email) : $warnings[] = "Regel {$line}: ongeldig CC-adres \"{$email}\".";
            }
            if ($cc !== []) {
                $out['report_cc'] = Str::limit(implode(', ', $cc), 255, '');
            }
        }
        if (($row['visits_per_year'] ?? '') !== '') {
            $visits = self::visits($row['visits_per_year']);
            $visits === null
                ? $warnings[] = "Regel {$line}: onderhoud \"{$row['visits_per_year']}\" niet herkend (gebruik 0, 2, 4, 6 of 12)."
                : $out['visits_per_year'] = $visits;
        }
        if (($row['contract_start'] ?? '') !== '') {
            $date = self::date($row['contract_start']);
            $date === null
                ? $warnings[] = "Regel {$line}: startdatum \"{$row['contract_start']}\" niet herkend (gebruik dd-mm-jjjj)."
                : $out['contract_start'] = $date;
        }

        return $out;
    }

    /** @return array{name: string, url: string, host: string}|null */
    private static function site(string $raw): ?array
    {
        $raw = trim($raw);
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        $parts = parse_url($raw);
        $scheme = Str::lower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host']) || isset($parts['user'])) {
            return null;
        }
        $scheme = $scheme === 'http' && config('ocm.allow_http_sites') ? 'http' : 'https';
        try {
            $host = Canonical::host($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        } catch (\Throwable) {
            return null;
        }
        if (!str_contains($host, '.')) {
            return null;
        }
        $path = rtrim($parts['path'] ?? '', '/');

        return [
            'name' => preg_replace('/^www\./', '', $host),
            'url' => $scheme . '://' . $host . $path,
            'host' => $host,
        ];
    }

    private static function visits(string $value): ?int
    {
        $v = Str::lower(trim($value));
        if (in_array($v, ['geen', 'nee', 'n', '-', 'geen contract'], true)) {
            return 0;
        }
        if (str_contains($v, 'maand')) {
            return 12;
        }
        if (preg_match('/^(\d{1,2})/', $v, $m) && isset(Customer::VISITS[(int) $m[1]])) {
            return (int) $m[1];
        }

        return null;
    }

    private static function date(string $value): ?string
    {
        foreach (['!d-m-Y', '!d/m/Y', '!d.m.Y', '!Y-m-d', '!j-n-Y', '!j/n/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, trim($value));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private static function key(string $name): string
    {
        return Str::lower(preg_replace('/\s+/', ' ', trim($name)));
    }

    private static function otherWww(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
    }
}
