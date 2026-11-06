<?php

namespace App\Ocm;

use App\Models\Backup;
use App\Models\Site;
use App\Models\SiteJob;
use App\Models\UpdateSchedule;
use App\Models\VisualCheck;
use Carbon\CarbonImmutable;

/**
 * Automatische updates (ocm:update-schedules, elke 5 minuten).
 *
 * Een schema zet op de geplande dag en tijd de beschikbare updates klaar voor zijn sites, via
 * dezelfde weg als met de hand (backup en visuele controle vooraf, als die aan staan). Uitgesloten
 * plugins/thema's en geblokkeerde sites worden overgeslagen. Met rollback_on_visual worden de
 * bestanden van de backup teruggezet als de homepage na de updates zichtbaar veranderd is.
 */
final class UpdateSchedules
{
    /** @return array<int, array<string, mixed>> schema-id => resultaat, voor de schema's die nu aan de beurt waren */
    public static function runDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $out = [];
        foreach (UpdateSchedule::query()->where('enabled', true)->get() as $schedule) {
            if (self::isDue($schedule, $now)) {
                $out[$schedule->id] = self::run($schedule, $now);
            }
        }

        return $out;
    }

    public static function isDue(UpdateSchedule $schedule, CarbonImmutable $now): bool
    {
        $slot = $schedule->slotBefore($now);
        if ($slot === null || $now->diffInMinutes($slot, true) > UpdateSchedule::CATCH_UP_HOURS * 60) {
            return false;
        }
        if ($schedule->paused_until && $slot->timezone('Europe/Amsterdam')->toDateString() <= $schedule->paused_until->toDateString()) {
            return false;
        }

        return $schedule->last_run_at === null || $schedule->last_run_at->lt($slot);
    }

    /** @return array{sites: int, queued: int, excluded: list<string>, skipped: array<string, string>} */
    public static function run(UpdateSchedule $schedule, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        // Eerst markeren: ook als er iets misgaat, draait hetzelfde moment niet twee keer.
        $schedule->forceFill(['last_run_at' => $now])->save();

        $sites = $schedule->sitesFrom(Site::query()->with(['tags', 'customer.tags'])->whereNotNull('public_key')->orderBy('name')->get());
        $security = $schedule->security_only ? self::securityFixes($sites) : null;
        $result = ['sites' => $sites->count(), 'queued' => 0, 'excluded' => [], 'skipped' => []];

        foreach ($sites as $site) {
            if ($reason = $site->updatesBlockedReason()) {
                $result['skipped'][$site->name] = $reason;
                continue;
            }
            $available = collect($site->availableUpdates());
            foreach ((array) $schedule->types as $type) {
                if ($type === 'translations') {
                    // Vertalingen zijn geen beveiligingsupdates; bij "alleen beveiliging" overslaan.
                    $result['queued'] += $security === null ? Updates::queue($site, 'translations', [], null, $schedule->id) : 0;
                    continue;
                }
                $items = $available->where('type', $type)->filter(function ($u) use ($schedule, $security, $site, &$result) {
                    // Plugins en thema's: bij "alleen deze" alleen de gekozen onderdelen (WordPress en de OC-plugin vallen daarbuiten).
                    if (in_array($u['type'], ['plugin', 'theme'], true) && !$schedule->includes((string) $u['slug'])) {
                        return false;
                    }
                    if ($schedule->excludes((string) $u['slug'])) {
                        $result['excluded'][] = $site->name . ': ' . $u['name'];

                        return false;
                    }

                    return $security === null || isset($security[$site->id][$u['type'] . ':' . strtolower((string) $u['slug'])]);
                });
                if ($items->isNotEmpty()) {
                    $result['queued'] += Updates::queue($site, $type, $items->pluck('id')->all(), null, $schedule->id);
                }
            }
        }
        $result['excluded'] = array_values(array_unique($result['excluded']));
        $schedule->forceFill(['last_result' => $result + ['ran_at' => $now->toIso8601String()]])->save();
        Activity::log('update_schedule_run', ['schedule_id' => $schedule->id, 'name' => $schedule->name, 'sites' => $result['sites'], 'queued' => $result['queued'],
            'excluded' => count($result['excluded']), 'skipped' => count($result['skipped'])], null, null);

        return $result;
    }

    /**
     * Visuele controle klaar en de homepage is zichtbaar veranderd: als de updates van een schema
     * met rollback_on_visual kwamen, de bestanden uit de backup terugzetten (nooit de database).
     */
    public static function afterVisualCheck(VisualCheck $check): ?SiteJob
    {
        if (!$check->changed()) {
            return null;
        }
        $jobs = SiteJob::query()->whereIn('id', $check->job_ids ?? [])->whereNotNull('update_schedule_id')->get(['id', 'update_schedule_id']);
        $schedules = UpdateSchedule::query()->whereIn('id', $jobs->pluck('update_schedule_id')->unique())->where('rollback_on_visual', true)->get();
        if ($schedules->isEmpty()) {
            return null;
        }
        $jobIds = $jobs->pluck('id')->all();
        $backup = Backup::query()->where('site_id', $check->site_id)->where('status', 'ready')->whereNull('deleted_at')->latest('id')->get()
            ->first(fn (Backup $b) => array_intersect($b->update_job_ids ?? [], $jobIds) !== []);
        if ($backup === null) {
            Activity::log('auto_rollback_skipped', ['reason' => 'geen backup', 'percent' => $check->diff_percent, 'schedules' => $schedules->pluck('name')], $check->site, null);

            return null;
        }
        $job = Backups::restore($backup, ['files'], null);
        Activity::log('auto_rollback', ['backup_id' => $backup->backup_id, 'percent' => $check->diff_percent, 'schedules' => $schedules->pluck('name')], $check->site, null);

        return $job;
    }

    /**
     * Componenten met een bekende kwetsbaarheid die een update oplost.
     *
     * @param \Illuminate\Support\Collection<int, Site> $sites
     * @return array<int, array<string, true>> site-id => "type:slug" => true
     */
    private static function securityFixes(\Illuminate\Support\Collection $sites): array
    {
        $index = Vulnerabilities::index(array_keys(Vulnerabilities::componentKeys($sites)));
        $out = [];
        foreach ($sites as $site) {
            foreach (Vulnerabilities::forSite($site, $index) as $match) {
                if ($match['fixed'] !== null) {
                    $out[$site->id][$match['component']['type'] . ':' . strtolower((string) $match['component']['slug'])] = true;
                }
            }
        }

        return $out;
    }
}
