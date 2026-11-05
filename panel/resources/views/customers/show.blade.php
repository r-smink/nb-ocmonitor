@extends('layouts.app')
@section('title', $customer->name)
@section('content')
  <div class="dhead">
    <a class="back" href="{{ route('customers.index') }}" aria-label="Terug">←</a>
    <h2>{{ $customer->name }}</h2>
    <span class="badge">{{ $customer->contractLabel() }}</span>
    @if ($customer->report_enabled)<span class="badge ok">rapport</span>@endif
    @can('manage-sites')
      <div class="right"><a class="btn ghost small" href="{{ route('customers.edit', $customer) }}">Bewerken</a></div>
    @endcan
  </div>
  <p class="dsub">{{ $customer->contact_name }}{{ $customer->contact_name ? ' · ' : '' }}{{ $customer->email }}{{ $customer->phone ? ' · ' . $customer->phone : '' }}</p>

  <div class="cols">
    <div class="panel">
      <div class="panel-head">
        <h3>Volgend onderhoud</h3>
        @if ($next)
          @php($nextPlanned = $customer->plannedOn($next))
          <span @class(['badge', 'sec' => $nextPlanned->lessThan($today)])>{{ $nextPlanned->format('d-m-Y') }}{{ $nextPlanned->lessThan($today) ? ' · achterstallig' : '' }}{{ !$nextPlanned->isSameDay($next) ? ' · verplaatst van ' . $next->format('d-m') : '' }}</span>
        @endif
      </div>
      @if (!$customer->hasContract())
        <p class="muted">Geen onderhoudscontract. Kies een frequentie en startdatum bij <a href="{{ route('customers.edit', $customer) }}">Bewerken</a>.</p>
      @elseif (!$next)
        <p class="muted">Geen openstaande beurt.</p>
      @else
        @can('manage-sites')
          <form method="POST" action="{{ route('customers.updates', $customer) }}" class="row-actions" data-confirm="Alle beschikbare updates starten op de sites van {{ $customer->name }}?">
            @csrf
            <button class="btn small" type="submit" @disabled($openUpdates === 0)>Updates starten ({{ $openUpdates }})</button>
            <label class="check-grid"><span><input type="checkbox" name="core" value="1"> ook WordPress zelf</span></label>
          </form>
          <p class="note">Plugins, thema's en vertalingen op alle sites van deze klant, met kopie en automatische rollback per onderdeel. De voortgang zie je op <a href="{{ route('updates.index') }}">Updates</a>.</p>

          <form method="POST" action="{{ route('customers.complete', $customer) }}" class="form mt">
            @csrf
            <input type="hidden" name="due_on" value="{{ $next->toDateString() }}">
            <label>Notities bij deze beurt (komen in het rapport)<textarea name="notes" rows="3" maxlength="5000" placeholder="Bijv. back-up gecontroleerd, formulier getest, oude plugin verwijderd."></textarea></label>
            @if ($customer->report_enabled)
              <label class="check-grid"><span><input type="checkbox" name="send_report" value="1" checked> Rapport direct versturen naar {{ implode(', ', $customer->recipients()) ?: '(geen e-mailadres)' }}</span></label>
            @endif
            <div class="actions"><button class="btn" type="submit">Onderhoud van {{ $next->format('d-m-Y') }} afronden</button></div>
          </form>
        @endcan
      @endif
    </div>

    <div class="panel">
      <div class="panel-head"><h3>Rapport</h3>@if (!$customer->report_enabled)<span class="hint">Staat uit voor deze klant</span>@endif</div>
      <form method="POST" action="{{ route('customers.report.send', $customer) }}" class="form">
        @csrf
        <div class="form-row">
          <label>Vanaf<input type="date" name="from" value="{{ $reportFrom->toDateString() }}" required></label>
          <label>Tot en met<input type="date" name="to" value="{{ $reportTo->toDateString() }}" required></label>
        </div>
        <p class="note">Onderdelen: {{ collect($customer->sections())->map(fn ($s) => \App\Models\Customer::REPORT_SECTIONS[$s])->implode(', ') ?: 'geen' }}.</p>
        <div class="row-actions">
          <button class="btn ghost small" type="submit" formaction="{{ route('customers.report', $customer) }}" formtarget="_blank">Bekijken (PDF)</button>
          @can('manage-sites')
            <button class="btn small" type="submit" data-confirm-button="Rapport versturen naar {{ implode(', ', $customer->recipients()) }}?">Versturen</button>
          @endcan
        </div>
      </form>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Websites</h3><span class="badge">{{ $customer->sites->count() }}</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Site</th><th>Status</th><th>WordPress</th><th>PHP</th><th class="num">Updates</th><th>Laatst gezien</th></tr></thead>
        <tbody>
          @forelse ($customer->sites as $site)
            @php($level = $site->level())
            <tr data-href="{{ route('sites.show', $site) }}">
              <td><span class="dcell lvl-{{ $level }}"><span class="dot"></span><a href="{{ route('sites.show', $site) }}">{{ $site->name }}</a></span></td>
              <td>{{ ['ok' => 'In orde', 'warn' => 'Aandacht', 'crit' => 'Kritiek', 'pending' => 'Niet gekoppeld'][$level] }}</td>
              <td>{{ $site->wp_version ?? '–' }}</td>
              <td>{{ $site->php_version ?? '–' }}</td>
              <td class="num">{{ count($site->availableUpdates()) }}</td>
              <td>{{ $site->last_seen_at?->diffForHumans() ?? '–' }}</td>
            </tr>
          @empty
            <tr><td colspan="6" class="muted">Nog geen sites gekoppeld aan deze klant.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  @if ($schedule !== [])
    <div class="panel">
      <div class="panel-head"><h3>Planning</h3><span class="hint">Afgelopen jaar en komend jaar</span></div>
      <div class="table-wrap scroll-sm">
        <table>
          <thead><tr><th>Datum</th><th>Status</th><th>Afgerond door</th><th>Rapport</th><th>Notities</th><th></th></tr></thead>
          <tbody>
            @foreach (array_reverse($schedule) as $date)
              @php($run = $runs->get($date->toDateString()))
              @php($state = $run?->done_at ? 'done' : ($customer->plannedOn($date)->lessThan($today) ? 'overdue' : 'planned'))
              <tr>
                @php($plannedDate = $customer->plannedOn($date))
                <td>
                  {{ $plannedDate->format('d-m-Y') }}@if (!$plannedDate->isSameDay($date)) <span class="muted">(contract: {{ $date->format('d-m') }})</span>@endif
                  @can('manage-sites')
                    @if (!$run?->done_at)
                      <form method="POST" action="{{ route('customers.move', $customer) }}" class="row-actions">
                        @csrf
                        <input type="hidden" name="due_on" value="{{ $date->toDateString() }}">
                        <input type="date" name="planned_on" value="{{ $plannedDate->toDateString() }}" class="w-auto" aria-label="Nieuwe datum voor de beurt van {{ $date->format('d-m-Y') }}">
                        <button class="btn ghost small" type="submit">Verplaatsen</button>
                      </form>
                    @endif
                  @endcan
                </td>
                <td><span @class(['state', 'bad' => $state === 'overdue', 'idle' => $state === 'planned'])>{{ ['done' => 'afgerond', 'overdue' => 'niet afgerond', 'planned' => 'gepland'][$state] }}</span></td>
                <td>{{ $run?->done_at ? ($run->doneBy?->name ?? '') . ' · ' . $run->done_at->timezone('Europe/Amsterdam')->format('d-m-Y') : '' }}</td>
                <td>{{ $run?->report_sent_at?->timezone('Europe/Amsterdam')->format('d-m-Y') ?? '' }}</td>
                <td class="wrap">{{ \Illuminate\Support\Str::limit($run?->notes ?? '', 120) }}</td>
                <td class="num">
                  @can('manage-sites')
                    @if ($run?->done_at)
                      <form method="POST" action="{{ route('customers.reopen', [$customer, $run]) }}" class="inline">@csrf<button class="btn ghost small" type="submit">Heropenen</button></form>
                    @elseif ($state === 'overdue')
                      <form method="POST" action="{{ route('customers.complete', $customer) }}" class="inline">@csrf<input type="hidden" name="due_on" value="{{ $date->toDateString() }}"><button class="btn ghost small" type="submit">Afronden</button></form>
                    @endif
                  @endcan
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif

  @if ($customer->notes)
    <div class="panel narrow"><h3>Notities</h3><p class="wrap">{!! nl2br(e($customer->notes)) !!}</p></div>
  @endif
@endsection
