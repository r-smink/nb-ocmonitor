<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** Onderhoudsbeurten per jaar => label. */
    public const VISITS = [
        0 => 'Geen contract',
        2 => '2x per jaar',
        4 => '4x per jaar',
        6 => '6x per jaar',
        12 => 'Elke maand',
    ];

    /** Onderdelen die in het rapport kunnen staan, in deze volgorde. */
    public const REPORT_SECTIONS = [
        'summary' => 'Samenvatting',
        'uptime' => 'Beschikbaarheid (uptime)',
        'maintenance' => 'Onderhoudsmomenten en notities',
        'updates' => 'Uitgevoerde updates',
        'pending' => 'Openstaande updates',
        'security' => 'Beveiliging',
        'pagespeed' => 'PageSpeed',
        'environment' => 'Technische gegevens',
        'changes' => 'Overige wijzigingen',
    ];

    public const DEFAULT_SECTIONS = ['summary', 'uptime', 'maintenance', 'updates', 'security', 'pagespeed'];

    protected $fillable = ['name', 'contact_name', 'email', 'report_cc', 'phone', 'notes', 'visits_per_year', 'contract_start', 'report_enabled', 'report_sections'];

    protected function casts(): array
    {
        return [
            'contract_start' => 'immutable_date',
            'report_enabled' => 'boolean',
            'report_sections' => 'array',
            'visits_per_year' => 'integer',
        ];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class)->orderBy('name');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(MaintenanceRun::class);
    }

    public function hasContract(): bool
    {
        return isset(self::VISITS[$this->visits_per_year]) && $this->visits_per_year > 0 && $this->contract_start !== null;
    }

    public function contractLabel(): string
    {
        return self::VISITS[$this->visits_per_year] ?? self::VISITS[0];
    }

    public function intervalMonths(): int
    {
        return $this->visits_per_year > 0 ? intdiv(12, $this->visits_per_year) : 0;
    }

    /**
     * Onderhoudsdata tussen $from en $to (inclusief): de startdatum plus elke interval. Valt een
     * datum in het weekend, dan schuift hij naar maandag.
     *
     * @return list<CarbonImmutable>
     */
    public function dueDates(CarbonInterface $from, CarbonInterface $to): array
    {
        if (!$this->hasContract()) {
            return [];
        }
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();
        $dates = [];
        // Direct naar de buurt van $from springen in plaats van vanaf de start te tellen.
        $k = max(0, intdiv((int) $this->contract_start->diffInMonths($from, false), $this->intervalMonths()) - 1);
        for (; $k < 10000; $k++) {
            $date = self::shiftWeekend($this->contract_start->addMonthsNoOverflow($k * $this->intervalMonths()));
            if ($date->greaterThan($to)) {
                break;
            }
            if ($date->greaterThanOrEqualTo($from)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Eerstvolgende beurt die nog niet is afgerond. Een achterstallige beurt telt mee tot één
     * interval terug, zodat een oude startdatum niet alles achterstallig maakt.
     */
    public function nextDue(?CarbonInterface $today = null): ?CarbonImmutable
    {
        if (!$this->hasContract()) {
            return null;
        }
        $today = CarbonImmutable::parse($today ?? now())->startOfDay();
        $done = $this->runs->whereNotNull('done_at')->map(fn (MaintenanceRun $r) => $r->due_on->toDateString())->all();
        $from = $today->subMonthsNoOverflow($this->intervalMonths())->addDay();
        foreach ($this->dueDates($from->max($this->contract_start), $today->addMonthsNoOverflow($this->intervalMonths() + 1)) as $date) {
            if (!in_array($date->toDateString(), $done, true)) {
                return $date;
            }
        }

        return null;
    }

    /** @return string[] */
    public function sections(): array
    {
        $sections = is_array($this->report_sections) ? $this->report_sections : self::DEFAULT_SECTIONS;

        return array_values(array_filter(array_keys(self::REPORT_SECTIONS), fn ($s) => in_array($s, $sections, true)));
    }

    /** @return string[] */
    public function recipients(): array
    {
        $cc = preg_split('/[\s,;]+/', (string) $this->report_cc) ?: [];

        return array_values(array_unique(array_filter(array_merge([$this->email], $cc), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }

    private static function shiftWeekend(CarbonImmutable $date): CarbonImmutable
    {
        return match ($date->dayOfWeekIso) {
            6 => $date->addDays(2),
            7 => $date->addDay(),
            default => $date,
        };
    }
}
