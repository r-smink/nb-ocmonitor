<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Site;
use App\Models\Tag;
use App\Models\UpdateSchedule;
use App\Ocm\Activity;
use App\Ocm\Backups;
use App\Ocm\UpdateSchedules;
use App\Ocm\Visual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Updates → Automatisch: update-schema's. Bekijken mag iedereen, wijzigen alleen beheerders. */
class UpdateScheduleController extends Controller
{
    public function index(): View
    {
        return view('updates.schedules.index', [
            'schedules' => UpdateSchedule::query()->orderBy('name')->get(),
            'sites' => Site::query()->with(['tags', 'customer.tags'])->whereNotNull('public_key')->get(),
            'backups' => Backups::enabled(),
            'visual' => Visual::enabled(),
        ]);
    }

    public function create(): View
    {
        return view('updates.schedules.form', $this->formData(new UpdateSchedule([
            'name' => '', 'enabled' => true, 'scope' => 'all', 'types' => ['plugin', 'theme', 'translations'], 'days' => [2], 'time' => '06:00',
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $schedule = UpdateSchedule::query()->create($this->validated($request) + ['created_by' => $request->user()->id]);
        Activity::log('update_schedule_created', ['schedule_id' => $schedule->id, 'name' => $schedule->name]);

        return redirect()->route('updates.schedules.index')->with('status', "Schema \"{$schedule->name}\" opgeslagen. Volgende keer: " . ($schedule->nextRun()?->format('d-m H:i') ?? 'niet gepland') . '.');
    }

    public function edit(UpdateSchedule $schedule): View
    {
        return view('updates.schedules.form', $this->formData($schedule));
    }

    public function update(Request $request, UpdateSchedule $schedule): RedirectResponse
    {
        $schedule->update($this->validated($request));
        Activity::log('update_schedule_updated', ['schedule_id' => $schedule->id, 'name' => $schedule->name]);

        return redirect()->route('updates.schedules.index')->with('status', "Schema \"{$schedule->name}\" bijgewerkt.");
    }

    public function destroy(UpdateSchedule $schedule): RedirectResponse
    {
        Activity::log('update_schedule_deleted', ['schedule_id' => $schedule->id, 'name' => $schedule->name]);
        $schedule->delete();

        return redirect()->route('updates.schedules.index')->with('status', 'Schema verwijderd.');
    }

    public function runNow(UpdateSchedule $schedule): RedirectResponse
    {
        $r = UpdateSchedules::run($schedule);

        return back()->with('status', "{$r['queued']} update(s) klaargezet op {$r['sites']} site(s)" . ($r['excluded'] ? ', ' . count($r['excluded']) . ' uitgesloten' : '') . ($r['skipped'] ? ', ' . count($r['skipped']) . ' site(s) overgeslagen' : '') . '.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'enabled' => ['nullable', 'boolean'],
            'scope' => ['required', Rule::in(array_keys(UpdateSchedule::SCOPES))],
            'scope_tags' => ['nullable', 'array'], 'scope_tags.*' => ['integer'],
            'scope_customers' => ['nullable', 'array'], 'scope_customers.*' => ['integer'],
            'scope_sites' => ['nullable', 'array'], 'scope_sites.*' => ['integer'],
            'types' => ['required', 'array', 'min:1'], 'types.*' => [Rule::in(array_keys(UpdateSchedule::TYPES))],
            'security_only' => ['nullable', 'boolean'],
            'days' => ['required', 'array', 'min:1'], 'days.*' => ['integer', 'between:1,7'],
            'time' => ['required', 'date_format:H:i'],
            'exclude' => ['nullable', 'string', 'max:2000'],
            'paused_until' => ['nullable', 'date_format:Y-m-d'],
            'rollback_on_visual' => ['nullable', 'boolean'],
        ], ['types.required' => 'Kies minstens één soort update.', 'days.required' => 'Kies minstens één dag.']);

        $ids = match ($data['scope']) {
            'tags' => $data['scope_tags'] ?? [],
            'customers' => $data['scope_customers'] ?? [],
            'sites' => $data['scope_sites'] ?? [],
            default => [],
        };
        if ($data['scope'] !== 'all' && $ids === []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['scope' => 'Kies minstens één ' . ['tags' => 'tag', 'customers' => 'klant', 'sites' => 'site'][$data['scope']] . '.']);
        }
        $exclude = collect(preg_split('/[\s,]+/', (string) ($data['exclude'] ?? '')))->map(fn ($s) => strtolower(trim($s)))->filter()->unique()->take(200)->values()->all();

        return [
            'name' => trim($data['name']),
            'enabled' => !empty($data['enabled']),
            'scope' => $data['scope'],
            'scope_ids' => array_values(array_unique(array_map('intval', $ids))),
            'types' => array_values($data['types']),
            'security_only' => !empty($data['security_only']),
            'days' => array_values(array_unique(array_map('intval', $data['days']))),
            'time' => $data['time'],
            'exclude' => $exclude,
            'paused_until' => $data['paused_until'] ?? null,
            'rollback_on_visual' => !empty($data['rollback_on_visual']),
        ];
    }

    /** @return array<string, mixed> */
    private function formData(UpdateSchedule $schedule): array
    {
        return [
            'schedule' => $schedule,
            'tags' => Tag::query()->orderBy('name')->get(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'sites' => Site::query()->whereNotNull('public_key')->orderBy('name')->get(['id', 'name']),
            'backups' => Backups::enabled(),
            'visual' => Visual::enabled(),
        ];
    }
}
