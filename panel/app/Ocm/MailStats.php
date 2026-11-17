<?php

namespace App\Ocm;

use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aantallen verzonden en mislukte mails per dag, uit de inventaris van de SMTP-module
 * (client-plugin 0.17.0+). De site stuurt alleen dagen die zijn e-maillog nog compleet heeft;
 * het panel bewaart ze zelf, zodat ook een kwartaal te tonen is bij een korte bewaartermijn.
 */
final class MailStats
{
    /**
     * Opslaan. Een dag kan alleen groeien (de log wordt opgeruimd, niet herschreven); daarom de
     * hoogste waarde houden, zodat een geleegde log op de site de cijfers hier niet wist.
     *
     * @param mixed $daily lijst van [datum, verzonden, mislukt] van de site (niet te vertrouwen)
     */
    public static function store(Site $site, mixed $daily): void
    {
        if (!is_array($daily)) {
            return;
        }
        $today = CarbonImmutable::today()->addDay()->toDateString();
        $rows = [];
        foreach (array_slice($daily, 0, 120) as $d) {
            if (!is_array($d) || !is_string($d[0] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d[0]) !== 1 || $d[0] > $today) {
                continue;
            }
            $sent = max(0, min(10_000_000, (int) ($d[1] ?? 0)));
            $failed = max(0, min(10_000_000, (int) ($d[2] ?? 0)));
            $rows[$d[0]] = ['site_id' => $site->id, 'date' => $d[0], 'sent' => $sent, 'failed' => $failed];
        }
        if ($rows === []) {
            return;
        }
        $existing = DB::table('site_mail_stats')->where('site_id', $site->id)->whereIn('date', array_keys($rows))->get()->keyBy(fn ($r) => substr((string) $r->date, 0, 10));
        foreach ($rows as $date => &$row) {
            if ($old = $existing->get($date)) {
                $row['sent'] = max($row['sent'], (int) $old->sent);
                $row['failed'] = max($row['failed'], (int) $old->failed);
            }
        }
        unset($row);
        DB::table('site_mail_stats')->upsert(array_values($rows), ['site_id', 'date'], ['sent', 'failed']);
    }

    /**
     * Totalen over een periode (inclusief begin- en einddatum). since = eerste dag waarvan het
     * panel cijfers heeft, als dat ná het begin van de periode ligt; null = niets bekend.
     *
     * @return array{sent: int, failed: int, since: ?CarbonImmutable}|null
     */
    public static function summary(Site $site, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $q = DB::table('site_mail_stats')->where('site_id', $site->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        $row = (clone $q)->selectRaw('COUNT(*) AS days, SUM(sent) AS sent, SUM(failed) AS failed, MIN(date) AS first')->first();
        if ($row === null || (int) $row->days === 0) {
            return null;
        }
        $first = CarbonImmutable::parse((string) $row->first)->startOfDay();

        return ['sent' => (int) $row->sent, 'failed' => (int) $row->failed, 'since' => $first->gt($from->startOfDay()) ? $first : null];
    }
}
