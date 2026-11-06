<?php

namespace App\Http\Controllers;

use App\Mail\MaintenanceReport;
use App\Models\Customer;
use App\Models\MaintenanceRun;
use App\Models\Site;
use App\Ocm\Activity;
use App\Ocm\Reports\ReportData;
use App\Ocm\Reports\ReportDesign;
use App\Ocm\Reports\ReportPdf;
use App\Ocm\Updates;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerController extends Controller
{
    /** Klanten: de lijst met contract, sites, rapport en het volgende onderhoud. */
    public function index(): View
    {
        $today = CarbonImmutable::today();
        $customers = Customer::query()->with(['runs', 'sites', 'tags'])->orderBy('name')->get();

        return view('customers.index', [
            'customers' => $customers,
            'next' => $customers->mapWithKeys(fn (Customer $c) => [$c->id => ($due = $c->nextDue($today)) ? $c->plannedOn($due) : null]),
            'today' => $today,
        ]);
    }

    /** Onderhoud: de kalender met geplande beurten en wat er als eerste aan de beurt is. */
    public function maintenance(Request $request): View
    {
        $month = CarbonImmutable::createFromFormat('Y-m-d', ($request->query('maand') ?: now()->format('Y-m')) . '-01');
        $month = $month instanceof CarbonImmutable ? $month->startOfMonth() : CarbonImmutable::now()->startOfMonth();
        $today = CarbonImmutable::today();

        $customers = Customer::query()->with(['runs', 'sites', 'tags'])->orderBy('name')->get();
        $gridStart = $month->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        // Per dag: wie er onderhoud heeft en hoe het ervoor staat.
        $days = [];
        foreach ($customers as $customer) {
            $done = $customer->runs->whereNotNull('done_at')->keyBy(fn (MaintenanceRun $r) => $r->due_on->toDateString());
            foreach ($customer->plannedDates($gridStart, $gridEnd) as $item) {
                $due = $item['due']->toDateString();
                $days[$item['date']->toDateString()][] = ['customer' => $customer, 'due' => $due, 'moved' => $item['moved'],
                    'state' => isset($done[$due]) ? 'done' : ($item['date']->lessThan($today) ? 'overdue' : 'planned')];
            }
        }

        $upcoming = $customers->map(fn (Customer $c) => ['customer' => $c, 'date' => ($due = $c->nextDue($today)) ? $c->plannedOn($due) : null])
            ->filter(fn ($row) => $row['date'] !== null)->sortBy(fn ($row) => $row['date']->timestamp)->values();

        return view('maintenance.index', [
            'month' => $month,
            'gridStart' => $gridStart,
            'gridEnd' => $gridEnd,
            'days' => $days,
            'today' => $today,
            'upcoming' => $upcoming,
        ]);
    }

    public function create(): View
    {
        return view('customers.form', ['customer' => new Customer(['visits_per_year' => 4, 'report_enabled' => true]), 'sites' => self::assignableSites(null)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = Customer::query()->create($this->validated($request));
        $this->syncSites($customer, $request);
        Activity::log('customer_created', ['customer' => $customer->name]);

        return redirect()->route('customers.show', $customer)->with('status', 'Klant toegevoegd.');
    }

    public function show(Customer $customer): View
    {
        $customer->load(['sites', 'runs.doneBy']);
        $today = CarbonImmutable::today();
        $next = $customer->nextDue($today);
        $schedule = $customer->hasContract() ? $customer->dueDates($today->subYear(), $today->addYear()) : [];
        $runs = $customer->runs->keyBy(fn (MaintenanceRun $r) => $r->due_on->toDateString());
        $openUpdates = $customer->sites->sum(fn (Site $s) => count($s->availableUpdates()));
        [$from, $to] = self::defaultPeriod($customer, $today);

        return view('customers.show', [
            'customer' => $customer,
            'next' => $next,
            'schedule' => $schedule,
            'runs' => $runs,
            'today' => $today,
            'openUpdates' => $openUpdates,
            'reportFrom' => $from,
            'reportTo' => $to,
        ]);
    }

    public function edit(Customer $customer): View
    {
        return view('customers.form', ['customer' => $customer, 'sites' => self::assignableSites($customer)]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer->update($this->validated($request));
        $this->syncSites($customer, $request);
        Activity::log('customer_updated', ['customer' => $customer->name]);

        return redirect()->route('customers.show', $customer)->with('status', 'Klant opgeslagen.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        Activity::log('customer_deleted', ['customer' => $customer->name]);
        $customer->delete();

        return redirect()->route('customers.index')->with('status', 'Klant verwijderd. De sites blijven bestaan.');
    }

    /** Onderhoudsbeurt afronden, met notities en eventueel meteen het rapport versturen. */
    public function complete(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'due_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'send_report' => ['nullable', 'boolean'],
        ]);
        $run = self::run($customer, $data['due_on']);
        $run->fill(['done_at' => now(), 'done_by' => $request->user()->id, 'notes' => $data['notes'] ?? null])->save();
        Activity::log('maintenance_done', ['customer' => $customer->name, 'due_on' => $data['due_on']]);
        \App\Ocm\SiteSchedule::pushCustomer($customer);

        $status = 'Onderhoud van ' . CarbonImmutable::parse($data['due_on'])->format('d-m-Y') . ' afgerond.';
        if (!empty($data['send_report'])) {
            [$from] = self::defaultPeriod($customer, CarbonImmutable::parse($data['due_on']), $run);
            $status .= ' ' . $this->send($customer, $from, CarbonImmutable::today(), $run);
        }

        return back()->with('status', $status);
    }

    /**
     * Eén beurt naar een andere dag verplaatsen (slepen in de kalender of de datum op de klantpagina).
     * Alleen deze beurt: het contract en de volgende datums blijven zoals ze zijn.
     */
    public function move(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'due_on' => ['required', 'date_format:Y-m-d'],
            'planned_on' => ['required', 'date_format:Y-m-d'],
        ]);
        $due = CarbonImmutable::parse($data['due_on']);
        $planned = CarbonImmutable::parse($data['planned_on']);
        $isContractDate = collect($customer->dueDates($due->subDay(), $due->addDay()))->contains(fn ($d) => $d->isSameDay($due));
        if (!$isContractDate) {
            return back()->withErrors(['planned_on' => 'Dit is geen onderhoudsdatum uit het contract van deze klant.']);
        }
        $run = self::run($customer, $due->toDateString());
        if ($run->done_at !== null) {
            return back()->withErrors(['planned_on' => 'Deze beurt is al afgerond.']);
        }
        $run->planned_on = $planned->isSameDay($due) ? null : $planned->toDateString();
        $run->save();
        Activity::log('maintenance_moved', ['customer' => $customer->name, 'due_on' => $due->toDateString(), 'planned_on' => $planned->toDateString()]);
        \App\Ocm\SiteSchedule::pushCustomer($customer);

        return back()->with('status', 'Onderhoud van ' . $due->format('d-m-Y') . ($planned->isSameDay($due) ? ' staat weer op de contractdatum.' : ' verplaatst naar ' . $planned->locale('nl')->translatedFormat('l j F Y') . '. De volgende datums blijven hetzelfde.'));
    }

    /** Bestaande beurt op contractdatum, of een nieuwe. Op datum zoeken (SQLite bewaart ook een tijd). */
    private static function run(Customer $customer, string $dueOn): MaintenanceRun
    {
        return MaintenanceRun::query()->where('customer_id', $customer->id)->whereDate('due_on', $dueOn)->first()
            ?? new MaintenanceRun(['customer_id' => $customer->id, 'due_on' => $dueOn]);
    }

    public function reopen(Customer $customer, MaintenanceRun $run): RedirectResponse
    {
        abort_unless($run->customer_id === $customer->id, 404);
        $run->update(['done_at' => null, 'done_by' => null]);

        \App\Ocm\SiteSchedule::pushCustomer($customer);

        return back()->with('status', 'Onderhoud weer op open gezet.');
    }

    /** Alle beschikbare plugin-, thema- en vertalingsupdates op de sites van de klant in de wachtrij. */
    public function startUpdates(Request $request, Customer $customer): RedirectResponse
    {
        $queued = 0;
        $blocked = [];
        foreach ($customer->sites as $site) {
            if (!$site->isPaired()) {
                continue;
            }
            if ($reason = $site->updatesBlockedReason()) {
                $blocked[] = $site->name . ': ' . $reason;
                continue;
            }
            $updates = collect($site->availableUpdates());
            foreach (['plugin', 'theme'] as $type) {
                $ids = $updates->where('type', $type)->pluck('id')->all();
                if ($ids !== []) {
                    $queued += Updates::queue($site, $type, $ids, $request->user());
                }
            }
            $open = Updates::queuedIds($site);
            if (!in_array('translations', $open['translations'] ?? [], true)) {
                $queued += Updates::queue($site, 'translations', [], $request->user());
            }
            if ($request->boolean('core') && !in_array('wordpress', $open['core'] ?? [], true)) {
                $queued += Updates::queue($site, 'core', ['wordpress'], $request->user());
            }
        }

        $status = $queued > 0 ? "{$queued} update-opdracht(en) in de wachtrij." : 'Er waren geen updates om te starten.';
        if ($blocked !== []) {
            $status .= ' Niet mogelijk: ' . implode('; ', $blocked) . '.';
        }

        $redirect = $queued > 0 ? redirect()->route('updates.queue', ['klant' => $customer->id]) : back();

        return $redirect->with('status', $status);
    }

    /** Alles gecontroleerd bij deze klant: de back-ups van al zijn sites opruimen. */
    public function clearBackups(Request $request, Customer $customer): RedirectResponse
    {
        $n = \App\Ocm\Backups::deleteChecked($customer->sites()->get(), $request->user());

        return back()->with('status', $n > 0 ? "{$n} back-up(s) van {$customer->name} verwijderd." : 'Geen back-ups om op te ruimen.');
    }

    public function report(Request $request, Customer $customer): Response
    {
        [$from, $to] = $this->period($request, $customer);
        $pdf = ReportPdf::render(ReportData::build($customer, $from, $to));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline') . '; filename="' . self::filename($customer, $to) . '"',
        ]);
    }

    public function sendReport(Request $request, Customer $customer): RedirectResponse
    {
        [$from, $to] = $this->period($request, $customer);
        $run = $customer->runs()->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])->orderByDesc('due_on')->first();

        return back()->with('status', $this->send($customer, $from, $to, $run));
    }

    private function send(Customer $customer, CarbonImmutable $from, CarbonImmutable $to, ?MaintenanceRun $run): string
    {
        $recipients = $customer->recipients();
        if ($recipients === []) {
            throw ValidationException::withMessages(['email' => 'Deze klant heeft geen geldig e-mailadres voor het rapport.']);
        }
        $pdf = ReportPdf::render(ReportData::build($customer, $from, $to));
        Mail::to($recipients[0])->cc(array_slice($recipients, 1))
            ->send(new MaintenanceReport($customer, ReportDesign::get(), $from, $to, $pdf, self::filename($customer, $to)));
        $run?->update(['report_sent_at' => now()]);
        Activity::log('report_sent', ['customer' => $customer->name, 'to' => $recipients, 'from' => $from->toDateString(), 'until' => $to->toDateString()]);

        return 'Rapport verstuurd naar ' . implode(', ', $recipients) . '.';
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Request $request, Customer $customer): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        [$from, $to] = self::defaultPeriod($customer, CarbonImmutable::today());

        return [isset($data['from']) ? CarbonImmutable::parse($data['from']) : $from, isset($data['to']) ? CarbonImmutable::parse($data['to']) : $to];
    }

    /**
     * Standaardperiode: vanaf de vorige afgeronde beurt (of één interval terug) tot vandaag.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function defaultPeriod(Customer $customer, CarbonImmutable $until, ?MaintenanceRun $except = null): array
    {
        $previous = $customer->runs()->whereNotNull('done_at')->where('due_on', '<', $until->toDateString())
            ->when($except?->exists, fn ($q) => $q->whereKeyNot($except->id))->orderByDesc('due_on')->value('due_on');
        $months = $customer->intervalMonths() ?: 1;
        $from = $previous ? CarbonImmutable::parse($previous)->addDay() : $until->subMonthsNoOverflow($months);

        return [$from, $until];
    }

    private static function filename(Customer $customer, CarbonImmutable $to): string
    {
        return 'onderhoudsrapport-' . (\Illuminate\Support\Str::slug(str_replace('.', '-', $customer->reportName())) ?: 'klant') . '-' . $to->format('Y-m-d') . '.pdf';
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'report_cc' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                foreach (preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) as $email) {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $fail("Ongeldig e-mailadres: {$email}");
                    }
                }
            }],
            'phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'visits_per_year' => ['required', 'integer', Rule::in(array_keys(Customer::VISITS))],
            'contract_start' => ['nullable', 'required_unless:visits_per_year,0', 'date_format:Y-m-d'],
            'report_enabled' => ['nullable', 'boolean'],
            'widget_report' => ['nullable', 'boolean'],
            'report_sections' => ['nullable', 'array'],
            'report_sections.*' => ['string', Rule::in(array_keys(Customer::REPORT_SECTIONS))],
        ]);
        $data['report_enabled'] = $request->boolean('report_enabled');
        $data['widget_report'] = $request->boolean('widget_report');
        $data['report_sections'] = array_values($data['report_sections'] ?? []);

        return $data;
    }

    private function syncSites(Customer $customer, Request $request): void
    {
        $previous = Site::query()->where('customer_id', $customer->id)->get();
        $tags = array_map('intval', array_filter((array) $request->input('tags', []), 'is_numeric'));
        $customer->tags()->sync(\App\Models\Tag::query()->whereIn('id', $tags)->pluck('id')->all());
        $ids = array_map('intval', (array) $request->input('sites', []));
        Site::query()->where('customer_id', $customer->id)->whereNotIn('id', $ids)->update(['customer_id' => null]);
        Site::query()->whereIn('id', $ids)->update(['customer_id' => $customer->id, 'client_name' => $customer->name]);
        \App\Ocm\SiteSchedule::pushCustomer($customer->fresh());
        foreach ($previous->whereNotIn('id', $ids ?? []) as $old) {
            \App\Ocm\SiteSchedule::push($old->fresh());
        }
    }

    /** Sites zonder klant, plus de sites van deze klant. */
    private static function assignableSites(?Customer $customer)
    {
        return Site::query()->where(fn ($q) => $q->whereNull('customer_id')->when($customer?->exists, fn ($q2) => $q2->orWhere('customer_id', $customer->id)))
            ->orderBy('name')->get(['id', 'name', 'url', 'customer_id']);
    }
}
