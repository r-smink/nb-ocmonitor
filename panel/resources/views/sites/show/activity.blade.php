{{-- Sectie 7: recente activiteit, de opdrachten van het panel en (onderaan) site verwijderen. --}}
<section class="sd-section" id="sd-activiteit" data-spy="activiteit">
  <div class="panel">
    <div class="panel-head">
      <span class="sd-ic">@include('partials.icon', ['name' => 'activity', 'size' => 18])</span>
      <h3>Recente activiteit</h3>
      <span class="hint">alles wat er op de site veranderde, ook buiten het panel om</span>
    </div>
    <ul class="feed">
      @forelse ($events->take(12) as $event)
        <li>
          <span class="fdot {{ $event->dotLevel() }}" title="{{ $event->sourceLabel() }}"></span>
          <span class="grow" title="{{ $event->type }} {{ $event->object }}">{{ $event->summary() }} <span class="muted">· {{ $event->sourceLabel() }}</span></span>
          <span class="t">{{ $event->occurred_at->timezone('Europe/Amsterdam')->isToday() ? $event->occurred_at->timezone('Europe/Amsterdam')->format('H:i') : $event->occurred_at->timezone('Europe/Amsterdam')->format('d-m H:i') }}</span>
        </li>
      @empty
        <li class="muted">Nog geen wijzigingen ontvangen.</li>
      @endforelse
    </ul>
    @if ($events->count() > 12)
      <details class="mt">
        <summary>Alle wijzigingen ({{ $events->count() }})</summary>
        @include('partials.events-table', ['events' => $events, 'showSite' => false])
      </details>
    @endif
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Opdrachten</h3><span class="hint">wat het panel naar de site stuurde</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tijd</th><th>Command</th><th>Status</th><th>Door</th></tr></thead>
        <tbody>
          @forelse ($jobs as $job)
            <tr>
              <td>{{ $job->created_at->timezone('Europe/Amsterdam')->format('d-m H:i:s') }}</td>
              <td class="mono">{{ $job->command }}</td>
              <td class="wrap">@include('partials.job-result', ['job' => $job])</td>
              <td>{{ $job->requester?->name ?? 'systeem' }}</td>
            </tr>
          @empty
            <tr><td colspan="4" class="muted">Nog geen opdrachten.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  @can('manage-sites')
    <details class="panel sd-details sd-danger">
      <summary class="panel-head"><h3>Site verwijderen</h3><span class="hint">verwijdert de site en alle gegevens uit het panel</span></summary>
      <p class="note">Ontkoppel daarna ook de plugin op de site zelf.</p>
      <form method="POST" action="{{ route('sites.destroy', $site) }}" class="form mt" data-confirm="Site {{ $site->name }} definitief verwijderen?">
        @csrf
        @method('DELETE')
        <label>Typ <b>{{ $site->host }}</b> ter bevestiging<input name="confirm" autocomplete="off" required></label>
        <div class="actions"><button class="btn danger" type="submit">Verwijderen</button></div>
      </form>
    </details>
  @endcan
</section>
