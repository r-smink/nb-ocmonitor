<?php

namespace App\Ocm\Reports;

use App\Ocm\Ai\Claude;
use Illuminate\Support\Facades\Cache;

/**
 * Eén alinea in gewone taal bovenaan het rapport: wat is er deze periode gebeurd. Altijd uit de
 * cijfers van ReportData; met Claude aan herschrijft Claude dezelfde cijfers tot lopende tekst.
 * Naar Claude gaan alleen de cijfers, geen namen of domeinen. Het resultaat wordt bewaard per set
 * cijfers, zodat een rapport twee keer openen niet twee keer Claude aanroept.
 */
final class ReportSummary
{
    /** @param array<string, mixed> $data uit ReportData::build */
    public static function paragraph(array $data): string
    {
        $facts = self::facts($data);
        $text = self::text($facts);
        if (!Claude::enabled('report') || $facts['sites'] === 0) {
            return $text;
        }

        $key = 'ocm:report-summary:' . hash('sha256', json_encode($facts) . Claude::model());
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $answer = Claude::json(self::SYSTEM, "Cijfers van deze periode (JSON):\n" . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), self::SCHEMA, 2000);
        $paragraph = trim((string) ($answer['paragraph'] ?? ''));
        if ($paragraph === '' || mb_strlen($paragraph) > 1200) {
            return $text;
        }
        Cache::put($key, $paragraph, now()->addDays(60));

        return $paragraph;
    }

    /**
     * De cijfers waar de alinea op gebaseerd is.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function facts(array $data): array
    {
        $sites = $data['sites'] ?? [];
        $uptimes = array_values(array_filter(array_map(fn ($s) => $s['uptime']['percent'] ?? null, $sites), fn ($p) => $p !== null));
        $before = [];
        $after = [];
        foreach ($sites as $s) {
            $m = $s['pagespeed']['mobile'] ?? null;
            if ($m !== null && $m['before'] !== null && $m['performance'] !== null) {
                $before[] = (int) $m['before'];
                $after[] = (int) $m['performance'];
            }
        }
        $failed = 0;
        foreach ($sites as $s) {
            $failed += count(array_filter($s['updates'] ?? [], fn ($u) => !$u['ok']));
        }

        return [
            'period_days' => isset($data['from'], $data['to']) ? (int) $data['from']->diffInDays($data['to']) + 1 : null,
            'sites' => (int) ($data['totals']['sites'] ?? count($sites)),
            'updates_done' => (int) ($data['totals']['updates'] ?? 0),
            'updates_failed' => $failed,
            'updates_pending' => (int) ($data['totals']['pending'] ?? 0),
            'vulnerabilities_fixed' => (int) ($data['totals']['vulns_fixed'] ?? 0),
            'uptime_percent' => $uptimes !== [] ? round(array_sum($uptimes) / count($uptimes), 2) : null,
            'outages' => array_sum(array_map(fn ($s) => (int) ($s['uptime']['incidents'] ?? 0), $sites)),
            'pagespeed_mobile_before' => $before !== [] ? (int) round(array_sum($before) / count($before)) : null,
            'pagespeed_mobile_after' => $after !== [] ? (int) round(array_sum($after) / count($after)) : null,
            'failed_logins_blocked' => (int) ($data['totals']['failed_logins'] ?? 0),
        ];
    }

    /**
     * Vaste tekst uit de cijfers, zonder Claude.
     *
     * @param array<string, mixed> $f
     */
    public static function text(array $f): string
    {
        if ($f['sites'] === 0) {
            return '';
        }
        $n = fn (int $count, string $one, string $more) => number_format($count, 0, ',', '.') . ' ' . ($count === 1 ? $one : $more);
        $websites = $f['sites'] === 1 ? 'de website' : 'de ' . $f['sites'] . ' websites';
        $parts = [];

        if ($f['updates_done'] > 0) {
            $line = 'Deze periode ' . ($f['updates_done'] === 1 ? 'is er ' : 'zijn er ') . $n($f['updates_done'], 'update', 'updates') . ' uitgevoerd op ' . $websites;
            if ($f['vulnerabilities_fixed'] > 0) {
                $line .= ', waarmee ' . $n($f['vulnerabilities_fixed'], 'bekende kwetsbaarheid is', 'bekende kwetsbaarheden zijn') . ' gedicht';
            }
            $parts[] = $line . '.';
        } else {
            $parts[] = 'Deze periode waren er geen updates nodig op ' . $websites . '.';
        }
        if ($f['updates_failed'] > 0) {
            $parts[] = $n($f['updates_failed'], 'update is', 'updates zijn') . ' niet gelukt; die staan nog open.';
        }

        if ($f['uptime_percent'] !== null) {
            $line = ucfirst($websites) . ' ' . ($f['sites'] === 1 ? 'was' : 'waren') . ' ' . number_format($f['uptime_percent'], $f['uptime_percent'] >= 99.995 ? 0 : 2, ',', '') . '% van de tijd bereikbaar';
            $parts[] = $line . ($f['outages'] > 0 ? ', met ' . $n($f['outages'], 'storing', 'storingen') . '.' : '.');
        }

        if ($f['pagespeed_mobile_before'] !== null && $f['pagespeed_mobile_after'] !== null) {
            $b = $f['pagespeed_mobile_before'];
            $a = $f['pagespeed_mobile_after'];
            if ($a === $b) {
                $parts[] = "De PageSpeed-score op mobiel bleef {$a}.";
            } else {
                $pct = $b > 0 ? (int) round(abs($a - $b) / $b * 100) : null;
                $parts[] = "De PageSpeed-score op mobiel ging van {$b} naar {$a}" . ($pct ? " ({$pct}% " . ($a > $b ? 'beter' : 'lager') . ')' : '') . '.';
            }
        }

        if ($f['failed_logins_blocked'] > 0) {
            $parts[] = 'Er ' . ($f['failed_logins_blocked'] === 1 ? 'is 1 verdachte inlogpoging' : 'zijn ' . $n($f['failed_logins_blocked'], '', 'verdachte inlogpogingen')) . ' tegengehouden.';
        }
        if ($f['updates_pending'] > 0) {
            $parts[] = $n($f['updates_pending'], 'update staat', 'updates staan') . ' nog klaar voor het volgende onderhoud.';
        }

        return implode(' ', array_map(fn ($p) => preg_replace('/\s+/', ' ', $p), $parts));
    }

    private const SYSTEM = <<<'TXT'
        Je schrijft voor een webbureau de openingsalinea van een onderhoudsrapport voor een klant. Je krijgt alleen cijfers. Schrijf in het Nederlands één alinea van drie tot vijf zinnen in gewone taal, vriendelijk en zakelijk, zonder jargon, zonder aanhef en zonder opsommingstekens. Spreek de lezer niet direct aan; schrijf over "de website" of "de websites".

        Gebruik alleen de cijfers die je krijgt en verzin niets. Velden met null of 0 laat je weg, behalve als het goed nieuws is (bijvoorbeeld geen storingen). Rond niet anders af dan aangeleverd. Leg kort uit waarom iets goed is voor de klant (bijvoorbeeld: gedichte kwetsbaarheden maken de site veiliger). Een lagere PageSpeed-score benoem je eerlijk maar rustig.

        Betekenis van de velden: updates_done = uitgevoerde updates, updates_failed = niet gelukt (staan nog open), updates_pending = nog open, vulnerabilities_fixed = bekende kwetsbaarheden opgelost door de updates, uptime_percent = gemiddelde bereikbaarheid, outages = aantal storingen, pagespeed_mobile_before/after = gemiddelde PageSpeed-score op mobiel (0-100) voor en na, failed_logins_blocked = tegengehouden verdachte inlogpogingen.
        TXT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => ['paragraph' => ['type' => 'string']],
        'required' => ['paragraph'],
        'additionalProperties' => false,
    ];
}
