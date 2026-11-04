<?php

namespace App\Ocm\Alerts;

use App\Mail\AlertDigest;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\Site;
use App\Models\User;
use App\Ocm\SiteIssues;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Meldingen (ocm:alerts, elke 5 minuten).
 *
 * 1. track(): de kritieke en aandachtspunten van alle sites worden incidenten (tabel alerts). Een
 *    incident blijft open zolang het issue terugkomt en is opgelost zodra het verdwijnt.
 * 2. deliver(): per ontvanger (portalgebruiker of kanaal) één bericht met wat nieuw, kritiek
 *    geworden of opgelost is. Een incident wordt pas gemeld als het minstens delay_minutes open
 *    staat (geen meldingen bij een korte hapering) en daarna nooit opnieuw, behalve als
 *    herinnering (repeat_hours) of als het van aandacht naar kritiek gaat.
 */
final class Alerts
{
    /** Zoveel keer opnieuw proberen bij een mislukte verzending, daarna opgeven. */
    private const MAX_ATTEMPTS = 3;

    public const STATE = 'alerts_state';

    public static function enabled(): bool
    {
        return (bool) config('ocm.alerts.enabled', true);
    }

    /**
     * @param Collection<int, Site>|null $sites standaard alle sites
     * @return array{opened: int, resolved: int}
     */
    public static function track(?Collection $sites = null, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $sites ??= Site::query()->get();
        SiteIssues::load($sites);
        // Eerste keer: alles wat al speelt is de bestaande situatie en wordt niet gemeld.
        $firstRun = (\App\Models\Setting::get(self::STATE)['tracked_at'] ?? null) === null;

        $open = Alert::query()->whereNull('resolved_at')->get()->keyBy(fn (Alert $a) => $a->site_id . '|' . $a->key);
        $seen = [];
        $opened = 0;
        foreach ($sites as $site) {
            if (!$site->isPaired()) {
                continue;
            }
            foreach ($site->issues() as $issue) {
                if (!in_array($issue['level'], ['crit', 'warn'], true)) {
                    continue;
                }
                $id = $site->id . '|' . $issue['key'];
                if (isset($seen[$id])) {
                    continue; // twee issues met dezelfde sleutel: de eerste (ernstigste) telt
                }
                $seen[$id] = true;
                $alert = $open->get($id);
                if ($alert === null) {
                    Alert::query()->create([
                        'site_id' => $site->id, 'key' => $issue['key'], 'level' => $issue['level'], 'category' => $issue['category'],
                        'text' => mb_substr($issue['text'], 0, 500), 'opened_at' => $now, 'last_seen_at' => $now, 'notify' => !$firstRun,
                    ]);
                    $opened++;
                    continue;
                }
                $alert->text = mb_substr($issue['text'], 0, 500);
                $alert->last_seen_at = $now;
                if ($issue['level'] === 'crit' && $alert->level === 'warn') {
                    $alert->escalated_at = $now;
                }
                $alert->level = $issue['level'];
                $alert->save();
            }
        }

        // Wat niet meer voorkomt (of van een site die niet meer gekoppeld is) is opgelost.
        $siteIds = $sites->pluck('id')->all();
        $resolved = 0;
        foreach ($open as $id => $alert) {
            if (!isset($seen[$id]) && in_array($alert->site_id, $siteIds, true)) {
                $alert->update(['resolved_at' => $now]);
                $resolved++;
            }
        }

        if ($firstRun) {
            \App\Models\Setting::put(self::STATE, ['tracked_at' => $now->toIso8601String()]);
        }

        return ['opened' => $opened, 'resolved' => $resolved];
    }

    /**
     * @return array<string, int> ontvanger => aantal verstuurde items
     */
    public static function deliver(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $delay = max(0, (int) config('ocm.alerts.delay_minutes', 10));
        $repeat = max(0, (int) config('ocm.alerts.repeat_hours', 0));

        $alerts = Alert::query()->with(['site.tags', 'site.customer.tags'])->where('notify', true)
            ->where(fn ($q) => $q->whereNull('resolved_at')->where('opened_at', '<=', $now->copy()->subMinutes($delay))
                ->orWhere('resolved_at', '>=', $now->copy()->subDays(2)))
            ->get()->filter(fn (Alert $a) => $a->site !== null);
        if ($alerts->isEmpty()) {
            return [];
        }
        $deliveries = AlertDelivery::query()->whereIn('alert_id', $alerts->pluck('id'))->get()->groupBy(fn ($d) => $d->recipient . '|' . $d->alert_id);

        $sent = [];
        foreach (self::recipients() as $recipient => [$filter, $send]) {
            $items = [];
            foreach ($alerts as $alert) {
                $kind = self::due($alert, $filter, $deliveries->get($recipient . '|' . $alert->id, collect()), $repeat, $now);
                if ($kind !== null && $filter->allowsNow($kind === 'resolve' ? 'info' : $alert->level, $now)) {
                    $items[] = [$alert, $kind];
                }
            }
            if ($items === []) {
                continue;
            }
            $payload = array_map(fn ($pair) => self::item($pair[0], $pair[1]), $items);
            $error = null;
            try {
                $send($payload);
            } catch (\Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 255);
                Log::warning('Melding versturen mislukt', ['recipient' => $recipient, 'error' => $error]);
            }
            foreach ($items as [$alert, $kind]) {
                AlertDelivery::query()->create([
                    'alert_id' => $alert->id, 'recipient' => $recipient, 'kind' => $kind, 'level' => $alert->level,
                    'sent_at' => $error === null ? $now : null, 'error' => $error,
                ]);
            }
            $sent[$recipient] = $error === null ? count($items) : 0;
        }

        return $sent;
    }

    /**
     * Wat deze ontvanger over dit incident moet horen, of null.
     *
     * @param Collection<int, AlertDelivery> $history
     */
    private static function due(Alert $alert, AlertFilter $filter, Collection $history, int $repeat, CarbonInterface $now): ?string
    {
        $done = $history->whereNotNull('sent_at');
        $announced = $done->whereIn('kind', ['open', 'escalate', 'reminder']);

        if (!$alert->isOpen()) {
            // Opgelost: alleen melden aan wie het incident ook gemeld kreeg.
            $kind = $announced->isNotEmpty() && $done->where('kind', 'resolve')->isEmpty() && $filter->prefs['resolved'] ? 'resolve' : null;
        } elseif (!$filter->matches($alert, $alert->site)) {
            return null;
        } elseif ($announced->isEmpty()) {
            $kind = 'open';
        } elseif ($alert->level === 'crit' && $announced->where('level', 'crit')->isEmpty()) {
            $kind = 'escalate';
        } elseif ($repeat > 0 && $alert->level === 'crit' && $announced->max('sent_at')->lt($now->copy()->subHours($repeat))) {
            $kind = 'reminder';
        } else {
            return null;
        }
        if ($kind === null) {
            return null;
        }

        // Na een paar mislukte pogingen geven we het op (geen fout per 5 minuten in de log).
        return $history->where('kind', $kind)->whereNull('sent_at')->count() >= self::MAX_ATTEMPTS ? null : $kind;
    }

    /** @return array<string, array{0: AlertFilter, 1: callable(list<array<string, mixed>>): void}> */
    private static function recipients(): array
    {
        $out = [];
        foreach (User::query()->orderBy('id')->get() as $user) {
            $filter = AlertFilter::forUser($user);
            if ($filter->enabled()) {
                $out['user:' . $user->id] = [$filter, fn (array $items) => Mail::to($user->email)->send(new AlertDigest($user, $items))];
            }
        }
        foreach (Channels::active() as $type => $channel) {
            if ($channel['filter']->enabled()) {
                $out['channel:' . $type] = [$channel['filter'], fn (array $items) => Channels::send($type, $channel['url'], $channel['secret'], $items)];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function item(Alert $alert, string $kind): array
    {
        $site = $alert->site;

        return [
            'kind' => $kind,
            'level' => $alert->level,
            'category' => $alert->category,
            'category_label' => SiteIssues::CATEGORIES[$alert->category] ?? $alert->category,
            'text' => $alert->text,
            'site' => ['id' => $site->site_id, 'name' => $site->name, 'url' => $site->url],
            'opened_at' => $alert->opened_at->toIso8601String(),
            'resolved_at' => $alert->resolved_at?->toIso8601String(),
            'duration' => $alert->duration(),
            'link' => route('sites.show', $site),
        ];
    }
}
