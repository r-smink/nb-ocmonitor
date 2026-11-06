@extends('layouts.app')
@section('title', 'Onderhoud')
@section('content')
  <div class="page-head">
    <div>
      <h1>Onderhoud</h1>
      <p>De kalender met geplande onderhoudsbeurten van alle klanten en wat er als eerste aan de beurt is.</p>
    </div>
    @can('manage-sites')
      <div class="right row-actions"><a class="btn ghost" href="{{ route('customers.index') }}">Klanten</a></div>
    @endcan
  </div>

  {{-- In de kalender en de lijst de websitenaam tonen (meerdere sites: "Site A +2"); zonder sites de klantnaam. --}}
  @php($siteLabel = fn ($c) => $c->sites->isEmpty() ? $c->name : $c->sites->sortBy('name')->first()->name . ($c->sites->count() > 1 ? ' +' . ($c->sites->count() - 1) : ''))
  <div class="cols cols-wide">
    <section class="panel">
      <div class="panel-head">
        <h3>Onderhoudskalender</h3>
        <div class="right cal-nav">
          <a class="btn ghost small" href="{{ route('maintenance.index', ['maand' => $month->subMonth()->format('Y-m')]) }}" aria-label="Vorige maand">←</a>
          <b>{{ $month->locale('nl')->translatedFormat('F Y') }}</b>
          <a class="btn ghost small" href="{{ route('maintenance.index', ['maand' => $month->addMonth()->format('Y-m')]) }}" aria-label="Volgende maand">→</a>
          @if (!$month->isSameMonth($today))<a class="btn ghost small" href="{{ route('maintenance.index') }}">Vandaag</a>@endif
        </div>
      </div>
      <div class="cal">
        @foreach (['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'] as $dow)<div class="dow">{{ $dow }}</div>@endforeach
        @for ($day = $gridStart; $day->lessThanOrEqualTo($gridEnd); $day = $day->addDay())
          <div @class(['out' => !$day->isSameMonth($month), 'today' => $day->isSameDay($today)]) @can('manage-sites') data-cal-drop="{{ $day->toDateString() }}" @endcan>
            <span class="day-no">{{ $day->day }}</span>
            @foreach ($days[$day->toDateString()] ?? [] as $item)
              @php($movable = $item['state'] !== 'done' && auth()->user()->can('manage-sites'))
              <a @class(['cal-item', $item['state'], 'moved' => $item['moved']]) href="{{ route('customers.show', $item['customer']) }}"
                 title="{{ $item['customer']->sites->sortBy('name')->pluck('name')->implode(', ') ?: $item['customer']->name }} ({{ $item['customer']->name }}) · {{ ['done' => 'afgerond', 'overdue' => 'achterstallig', 'planned' => 'gepland'][$item['state']] }}{{ $item['moved'] ? ' · verplaatst van ' . \Carbon\Carbon::parse($item['due'])->format('d-m') : '' }}{{ $movable ? ' · sleep naar een andere dag om te verplaatsen' : '' }}"
                 @if ($movable) draggable="true" data-cal-move="{{ route('customers.move', $item['customer']) }}" data-due="{{ $item['due'] }}" @endif>{{ $siteLabel($item['customer']) }}</a>
            @endforeach
          </div>
        @endfor
      </div>
      <p class="note mt">Blauw = gepland, groen = afgerond, rood = achterstallig, gestippeld = verplaatst. Valt een beurt in het weekend, dan staat hij op maandag. Sleep een beurt naar een andere dag om alleen die beurt te verplaatsen; de volgende datums blijven hetzelfde.</p>
      @can('manage-sites')
        <form method="POST" id="calMoveForm" class="hidden">
          @csrf
          <input type="hidden" name="due_on">
          <input type="hidden" name="planned_on">
        </form>
      @endcan
    </section>

    <section class="panel">
      <div class="panel-head"><h3>Eerstvolgend onderhoud</h3></div>
      <div class="mini-list scroll-sm">
        @forelse ($upcoming->take(15) as $row)
          @php($overdue = $row['date']->lessThan($today))
          <div class="mini-row">
            <div class="grow">
              <a class="name" href="{{ route('customers.show', $row['customer']) }}" title="{{ $row['customer']->sites->sortBy('name')->pluck('name')->implode(', ') }}">{{ $siteLabel($row['customer']) }}</a>
              <div @class(['sub', 'issue-text', 'crit' => $overdue])>@if ($row['customer']->sites->isNotEmpty()){{ $row['customer']->name }} · @endif{{ $row['date']->format('d-m-Y') }}{{ $overdue ? ' · achterstallig' : ($row['date']->isSameDay($today) ? ' · vandaag' : '') }}</div>
            </div>
            <span class="sub">{{ $row['customer']->sites->count() }} {{ $row['customer']->sites->count() === 1 ? 'site' : 'sites' }} · {{ $row['customer']->sites->sum(fn ($s) => count($s->availableUpdates())) }} upd.</span>
          </div>
        @empty
          <p class="muted">Nog geen klanten met een contract.</p>
        @endforelse
      </div>
    </section>
  </div>

@endsection
