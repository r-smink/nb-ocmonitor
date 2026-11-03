@extends('layouts.app')
@section('title', 'Klanten')
@section('content')
  <div class="page-head">
    <div>
      <h1>Klanten</h1>
      <p>Klanten met een onderhoudscontract, de kalender met geplande beurten en de rapporten die ze krijgen.</p>
    </div>
    @can('manage-sites')
      <div class="right row-actions"><a class="btn ghost" href="{{ route('tags.index') }}">Tags</a><a class="btn" href="{{ route('customers.create') }}">Klant toevoegen</a></div>
    @endcan
  </div>

  <div class="cols cols-wide">
    <section class="panel">
      <div class="panel-head">
        <h3>Onderhoudskalender</h3>
        <div class="right cal-nav">
          <a class="btn ghost small" href="{{ route('customers.index', ['maand' => $month->subMonth()->format('Y-m')]) }}" aria-label="Vorige maand">←</a>
          <b>{{ $month->locale('nl')->translatedFormat('F Y') }}</b>
          <a class="btn ghost small" href="{{ route('customers.index', ['maand' => $month->addMonth()->format('Y-m')]) }}" aria-label="Volgende maand">→</a>
          @if (!$month->isSameMonth($today))<a class="btn ghost small" href="{{ route('customers.index') }}">Vandaag</a>@endif
        </div>
      </div>
      <div class="cal">
        @foreach (['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'] as $dow)<div class="dow">{{ $dow }}</div>@endforeach
        @for ($day = $gridStart; $day->lessThanOrEqualTo($gridEnd); $day = $day->addDay())
          <div @class(['out' => !$day->isSameMonth($month), 'today' => $day->isSameDay($today)])>
            <span class="day-no">{{ $day->day }}</span>
            @foreach ($days[$day->toDateString()] ?? [] as $item)
              <a class="cal-item {{ $item['state'] }}" href="{{ route('customers.show', $item['customer']) }}" title="{{ $item['customer']->name }} · {{ ['done' => 'afgerond', 'overdue' => 'achterstallig', 'planned' => 'gepland'][$item['state']] }}">{{ $item['customer']->name }}</a>
            @endforeach
          </div>
        @endfor
      </div>
      <p class="note mt">Blauw = gepland, groen = afgerond, rood = achterstallig. Valt een beurt in het weekend, dan staat hij op maandag.</p>
    </section>

    <section class="panel">
      <div class="panel-head"><h3>Eerstvolgend onderhoud</h3></div>
      <div class="mini-list scroll-sm">
        @forelse ($upcoming->take(15) as $row)
          @php($overdue = $row['date']->lessThan($today))
          <div class="mini-row">
            <div class="grow">
              <a class="name" href="{{ route('customers.show', $row['customer']) }}">{{ $row['customer']->name }}</a>
              <div @class(['sub', 'issue-text', 'crit' => $overdue])>{{ $row['date']->format('d-m-Y') }}{{ $overdue ? ' · achterstallig' : ($row['date']->isSameDay($today) ? ' · vandaag' : '') }}</div>
            </div>
            <span class="sub">{{ $row['customer']->sites->count() }} {{ $row['customer']->sites->count() === 1 ? 'site' : 'sites' }} · {{ $row['customer']->sites->sum(fn ($s) => count($s->availableUpdates())) }} upd.</span>
          </div>
        @empty
          <p class="muted">Nog geen klanten met een contract.</p>
        @endforelse
      </div>
    </section>
  </div>

  <section class="panel">
    <div class="panel-head"><h3>Alle klanten</h3><span class="badge">{{ $customers->count() }}</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Klant</th><th>Contract</th><th>Sites</th><th>Rapport</th>@can('manage-sites')<th></th>@endcan</tr></thead>
        <tbody>
          @forelse ($customers as $customer)
            @php($pending = $customer->sites->filter(fn ($s) => $s->isPaired() && $s->updatesBlockedReason() === null)->sum(fn ($s) => collect($s->availableUpdates())->whereIn('type', ['plugin', 'theme'])->count()))
            <tr data-href="{{ route('customers.show', $customer) }}">
              <td>
                <a href="{{ route('customers.show', $customer) }}"><b>{{ $customer->name }}</b></a> @include('partials.tags', ['tags' => $customer->tags])
                <div class="note">{{ collect([$customer->contact_name, $customer->email])->filter()->implode(' · ') ?: '–' }}</div>
              </td>
              <td>{{ $customer->contractLabel() }}<div class="note">{{ $customer->contract_start ? 'sinds ' . $customer->contract_start->format('d-m-Y') : '' }}</div></td>
              <td class="wrap">{{ $customer->sites->pluck('name')->implode(', ') ?: '–' }}</td>
              <td>@if ($customer->report_enabled)<span class="state">rapport aan</span>@else<span class="state idle">geen rapport</span>@endif</td>
              @can('manage-sites')
                <td class="actions">
                  @if ($pending > 0)
                    <form method="POST" action="{{ route('customers.updates', $customer) }}" class="row-form" data-confirm="Alle beschikbare updates starten op de sites van {{ $customer->name }}?">
                      @csrf
                      <button class="btn small quiet" type="submit">Updates starten ({{ $pending }})</button>
                    </form>
                  @endif
                </td>
              @endcan
            </tr>
          @empty
            <tr><td colspan="5" class="muted">Nog geen klanten.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
@endsection
