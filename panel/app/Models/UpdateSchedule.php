<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class UpdateSchedule extends Model
{
    public const TYPES = ['plugin' => 'Plugins', 'theme' => 'Thema\'s', 'translations' => 'Vertalingen', 'core' => 'WordPress zelf', 'client' => 'OC-plugin'];

    public const DAYS = [1 => 'ma', 2 => 'di', 3 => 'wo', 4 => 'do', 5 => 'vr', 6 => 'za', 7 => 'zo'];

    public const SCOPES = ['all' => 'Alle sites', 'tags' => 'Sites met tag', 'customers' => 'Sites van klant', 'sites' => 'Losse sites'];

    /** Wordt de geplande tijd gemist (scheduler stond stil), dan tot zoveel uur erna nog inhalen. */
    public const CATCH_UP_HOURS = 2;

    protected $fillable = ['name', 'enabled', 'scope', 'scope_ids', 'types', 'security_only', 'days', 'time', 'exclude', 'paused_until', 'rollback_on_visual', 'created_by'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'scope_ids' => 'array',
            'types' => 'array',
            'security_only' => 'boolean',
            'days' => 'array',
            'exclude' => 'array',
            'paused_until' => 'date',
            'rollback_on_visual' => 'boolean',
            'last_run_at' => 'datetime',
            'last_result' => 'array',
        ];
    }

    /** Het laatste geplande moment op of vóór $now (Nederlandse tijd), of null als er geen dagen zijn. */
    public function slotBefore(CarbonImmutable $now): ?CarbonImmutable
    {
        $local = $now->timezone('Europe/Amsterdam');
        [$h, $m] = array_map('intval', explode(':', $this->time));
        for ($back = 0; $back <= 7; $back++) {
            $day = $local->subDays($back)->setTime($h, $m);
            if (in_array((int) $day->isoWeekday(), array_map('intval', $this->days ?? []), true) && $day->lte($local)) {
                return $day->utc();
            }
        }

        return null;
    }

    public function nextRun(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        if (!$this->enabled || ($this->days ?? []) === []) {
            return null;
        }
        $local = $now->timezone('Europe/Amsterdam');
        if ($this->paused_until && $local->lt($this->paused_until->copy()->addDay())) {
            $local = CarbonImmutable::parse($this->paused_until->toDateString(), 'Europe/Amsterdam')->addDay()->startOfDay();
        }
        [$h, $m] = array_map('intval', explode(':', $this->time));
        for ($ahead = 0; $ahead <= 7; $ahead++) {
            $day = $local->addDays($ahead)->setTime($h, $m);
            if ($day->gt($local) && in_array((int) $day->isoWeekday(), array_map('intval', $this->days), true)) {
                return $day;
            }
        }

        return null;
    }

    /**
     * Sites waarvoor dit schema geldt.
     *
     * @param Collection<int, Site> $sites met tags en customer.tags
     * @return Collection<int, Site>
     */
    public function sitesFrom(Collection $sites): Collection
    {
        $ids = array_map('intval', $this->scope_ids ?? []);

        return $sites->filter(fn (Site $s) => match ($this->scope) {
            'tags' => $s->allTags()->pluck('id')->intersect($ids)->isNotEmpty(),
            'customers' => in_array((int) $s->customer_id, $ids, true),
            'sites' => in_array((int) $s->id, $ids, true),
            default => true,
        })->values();
    }

    public function excludes(string $slug): bool
    {
        return in_array(strtolower($slug), array_map('strtolower', $this->exclude ?? []), true);
    }

    public function describe(): string
    {
        $days = collect($this->days ?? [])->sort()->map(fn ($d) => self::DAYS[(int) $d] ?? $d)->implode(', ');
        $types = collect($this->types ?? [])->map(fn ($t) => mb_strtolower(self::TYPES[$t] ?? $t))->implode(', ');

        return "{$days} om {$this->time} · {$types}" . ($this->security_only ? ' (alleen beveiligingsupdates)' : '');
    }
}
