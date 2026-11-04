<?php

namespace App\Ocm\Monitoring;

use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Storingsgeschiedenis en uptime (SLA) per periode. Monitor::applyState opent en sluit de
 * storingen; de uptime is de tijd sinds uptime_since min de tijd dat de site offline was.
 */
final class Incidents
{
    public static function open(Site $site, CarbonInterface $since, ?string $cause): void
    {
        if (!UptimeIncident::query()->where('site_id', $site->id)->whereNull('ended_at')->exists()) {
            UptimeIncident::query()->create(['site_id' => $site->id, 'started_at' => $since, 'cause' => $cause !== null ? mb_substr($cause, 0, 255) : null]);
        }
    }

    public static function close(Site $site, CarbonInterface $at): void
    {
        $open = UptimeIncident::query()->where('site_id', $site->id)->whereNull('ended_at')->get();
        if ($open->isEmpty() && $site->down_since !== null) {
            // Offline gegaan vóór er storingen werden bijgehouden.
            UptimeIncident::query()->create(['site_id' => $site->id, 'started_at' => $site->down_since, 'ended_at' => $at]);
        }
        foreach ($open as $incident) {
            $incident->update(['ended_at' => $at]);
        }
    }

    /**
     * Uptime in een periode, alleen over de tijd dat er gemeten werd.
     *
     * @return array{percent: ?float, incidents: int, down_minutes: int, list: Collection<int, UptimeIncident>}
     */
    public static function period(Site $site, CarbonInterface $from, CarbonInterface $to, ?Collection $incidents = null): array
    {
        $since = $site->uptime_since;
        $end = CarbonImmutable::parse(min($to, now()));
        $start = CarbonImmutable::parse($since && $since->gt($from) ? $since : $from);
        $incidents ??= UptimeIncident::query()->where('site_id', $site->id)->where('started_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))->orderBy('started_at')->get();
        $list = $incidents->filter(fn (UptimeIncident $i) => $i->started_at->lt($end) && ($i->ended_at === null || $i->ended_at->gt($start)))->values();

        if ($since === null || $start->gte($end)) {
            return ['percent' => null, 'incidents' => $list->count(), 'down_minutes' => 0, 'list' => $list];
        }
        $down = 0;
        foreach ($list as $i) {
            $a = max($i->started_at->getTimestamp(), $start->getTimestamp());
            $b = min(($i->ended_at ?? now())->getTimestamp(), $end->getTimestamp());
            $down += max(0, $b - $a);
        }
        $total = max(1, $end->getTimestamp() - $start->getTimestamp());

        return [
            'percent' => round(max(0, 100 - $down / $total * 100), 2),
            'incidents' => $list->count(),
            'down_minutes' => (int) round($down / 60),
            'list' => $list,
        ];
    }

    /**
     * Uptime per kalendermaand, nieuwste eerst.
     *
     * @return list<array{month: CarbonImmutable, percent: ?float, incidents: int, down_minutes: int}>
     */
    public static function monthly(Site $site, int $months = 6): array
    {
        $first = CarbonImmutable::now('Europe/Amsterdam')->startOfMonth()->subMonths($months - 1);
        $all = UptimeIncident::query()->where('site_id', $site->id)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $first))->orderBy('started_at')->get();
        $out = [];
        for ($n = 0; $n < $months; $n++) {
            $month = CarbonImmutable::now('Europe/Amsterdam')->startOfMonth()->subMonths($n);
            $p = self::period($site, $month, $month->endOfMonth(), $all);
            $out[] = ['month' => $month, 'percent' => $p['percent'], 'incidents' => $p['incidents'], 'down_minutes' => $p['down_minutes']];
        }

        return $out;
    }

    /** Eenmalig bij de migratie: storingen afleiden uit de bestaande losse controles. */
    public static function backfill(): void
    {
        foreach (Site::query()->get() as $site) {
            $since = null;
            $failStart = null;
            $failCause = null;
            $run = 0;
            foreach (UptimeCheck::query()->where('site_id', $site->id)->orderBy('checked_at')->lazy(500) as $check) {
                $since ??= $check->checked_at;
                if (!$check->ok) {
                    if ($run === 0) {
                        [$failStart, $failCause] = [$check->checked_at, $check->error];
                    }
                    $run++;
                    continue;
                }
                if ($run >= Monitor::FAILURES_FOR_DOWN) {
                    UptimeIncident::query()->create(['site_id' => $site->id, 'started_at' => $failStart, 'ended_at' => $check->checked_at, 'cause' => $failCause]);
                }
                $run = 0;
            }
            if ($run >= Monitor::FAILURES_FOR_DOWN || ($site->uptime_state === 'down' && $failStart !== null && $run > 0)) {
                UptimeIncident::query()->create(['site_id' => $site->id, 'started_at' => $site->down_since ?? $failStart, 'cause' => $failCause]);
            }
            if ($since !== null) {
                $site->forceFill(['uptime_since' => $since])->saveQuietly();
            }
        }
    }
}
