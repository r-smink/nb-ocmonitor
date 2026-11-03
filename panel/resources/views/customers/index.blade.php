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
      <div class="table-wrap scroll-sm">
        <table>
          <thead><tr><th>Datum</th><th>Klant</th><th>Sites</th><th class="num">Updates</th></tr></thead>
          <tbody>
            @forelse ($upcoming->take(15) as $row)
              @php($overdue = $row['date']->lessThan($today))
              <tr data-href="{{ route('customers.show', $row['customer']) }}">
                <td @class(['issue-text', 'crit' => $overdue])>{{ $row['date']->format('d-m-Y') }}{{ $overdue ? ' (achterstallig)' : ($row['date']->isSameDay($today) ? ' (vandaag)' : '') }}</td>
                <td><a href="{{ route('customers.show', $row['customer']) }}">{{ $row['customer']->name }}</a></td>
                <td>{{ $row['customer']->sites->count() }}</td>
                <td class="num">{{ $row['customer']->sites->sum(fn ($s) => count($s->availableUpdates())) }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="muted">Nog geen klanten met een contract.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <section class="panel">
    <div class="panel-head"><h3>Alle klanten</h3><span class="badge">{{ $customers->count() }}</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Klant</th><th>Contactpersoon</th><th>E-mail</th><th>Contract</th><th>Sinds</th><th>Sites</th><th>Rapport</th></tr></thead>
        <tbody>
          @forelse ($customers as $customer)
            <tr data-href="{{ route('customers.show', $customer) }}">
              <td><a href="{{ route('customers.show', $customer) }}"><b>{{ $customer->name }}</b></a> @include('partials.tags', ['tags' => $customer->tags])</td>
              <td>{{ $customer->contact_name }}</td>
              <td>{{ $customer->email ?: '–' }}</td>
              <td>{{ $customer->contractLabel() }}</td>
              <td>{{ $customer->contract_start?->format('d-m-Y') ?? '–' }}</td>
              <td class="wrap">{{ $customer->sites->pluck('name')->implode(', ') ?: '–' }}</td>
              <td>@if ($customer->report_enabled)<span class="state">ja</span>@else<span class="state idle">nee</span>@endif</td>
            </tr>
          @empty
            <tr><td colspan="7" class="muted">Nog geen klanten.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
@endsection
