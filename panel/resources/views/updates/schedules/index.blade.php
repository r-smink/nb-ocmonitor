@extends('layouts.app')
@section('title', 'Automatische updates')
@section('content')
  <div class="page-head">
    <div>
      <h1>Updates</h1>
      <p>Automatische updates per schema. Ze gaan via dezelfde weg als met de hand: eerst een backup en screenshot (als die aan staan), daarna de update met health check en rollback per component.</p>
    </div>
    @can('manage-sites')
      <div class="right"><a class="btn" href="{{ route('updates.schedules.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 16]) Schema toevoegen</a></div>
    @endcan
  </div>
  @include('updates.tabs')

  @if (!$backups || !$visual)
    <div class="alert warn">
      <p>{{ !$backups ? 'Backups vóór updates staan uit of Spaces is niet ingesteld.' : '' }} {{ !$visual ? 'De visuele controle staat uit (of er is geen PageSpeed API-sleutel).' : '' }} Automatisch terugzetten na een visuele wijziging werkt alleen als beide aan staan.</p>
    </div>
  @endif

  <section class="panel">
    @if ($schedules->isEmpty())
      <p class="muted">Nog geen schema's. Alle updates gaan nu met de hand.</p>
    @else
      <div class="schedule-list">
        @foreach ($schedules as $schedule)
          @php($next = $schedule->nextRun())
          @php($count = $schedule->sitesFrom($sites)->count())
          <div @class(['schedule', 'off' => !$schedule->enabled])>
            <div>
              <span class="name">{{ $schedule->name }}</span>
              @unless ($schedule->enabled)<span class="badge">uit</span>@endunless
              @if ($schedule->rollback_on_visual)<span class="badge">terugzetten bij visuele wijziging</span>@endif
              <div class="sub">{{ $schedule->describe() }} · {{ \App\Models\UpdateSchedule::SCOPES[$schedule->scope] }} ({{ $count }} {{ $count === 1 ? 'site' : 'sites' }})@if ($schedule->exclude) · nooit: {{ implode(', ', $schedule->exclude) }}@endif</div>
            </div>
            <div class="schedule-actions">
              @can('manage-sites')
                <form method="POST" action="{{ route('updates.schedules.run', $schedule) }}" class="inline" data-confirm="Schema {{ $schedule->name }} nu uitvoeren? De updates worden direct klaargezet.">
                  @csrf
                  <button class="btn ghost small" type="submit">Nu uitvoeren</button>
                </form>
                <a class="btn small quiet" href="{{ route('updates.schedules.edit', $schedule) }}">Bewerken</a>
              @endcan
            </div>
            <div class="sub">
              Volgende keer: <b>{{ $next ? $next->locale('nl')->translatedFormat('D d-m H:i') : 'niet gepland' }}</b>
              @if ($schedule->paused_until) · gepauzeerd t/m {{ $schedule->paused_until->format('d-m-Y') }}@endif
              @if ($schedule->last_run_at)
                · laatst {{ $schedule->last_run_at->diffForHumans() }}: {{ $schedule->last_result['queued'] ?? 0 }} update(s)@if (!empty($schedule->last_result['skipped'])), {{ count($schedule->last_result['skipped']) }} site(s) overgeslagen @endif
              @endif
            </div>
          </div>
        @endforeach
      </div>
    @endif
  </section>
@endsection
