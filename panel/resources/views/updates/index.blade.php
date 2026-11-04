@extends('layouts.app')
@section('title', 'Updates')
@if ($hasOpenJobs)
  @section('refresh', '10')
@endif
@section('content')
  @php($query = fn (array $extra) => array_filter(array_merge($filters, $extra), fn ($v) => $v !== null && $v !== ''))
  <div class="page-head">
    <div>
      <h1>Updates</h1>
      <p>Elke update maakt eerst een kopie; na afloop volgt een health check met automatische rollback.</p>
    </div>
    <div class="right summary">
      <span @class(['chip', 'warn', 'has' => $counts['plugin'] > 0])>{{ $counts['plugin'] }} plugins</span>
      <span @class(['chip', 'warn', 'has' => $counts['theme'] > 0])>{{ $counts['theme'] }} thema's</span>
      <span @class(['chip', 'crit', 'has' => $counts['core'] > 0])>{{ $counts['core'] }} WordPress</span>
    </div>
  </div>
  @include('updates.tabs')

  <div class="toolbar">
    <nav class="tabs-nav">
      <a href="{{ route('updates.index', $query(['type' => null, 'component' => null])) }}" @class(['on' => $filters['type'] === null])>Alles</a>
      @foreach ($types as $key => $label)
        <a href="{{ route('updates.index', $query(['type' => $key, 'component' => null])) }}" @class(['on' => $filters['type'] === $key])>{{ $label }} ({{ $counts[$key] }})</a>
      @endforeach
    </nav>
    <form class="filters" method="GET" action="{{ route('updates.index') }}">
      @if ($filters['type'])<input type="hidden" name="type" value="{{ $filters['type'] }}">@endif
      @include('partials.tag-select')
      <select name="component" data-autosubmit aria-label="Plugin of thema">
        <option value="">Alle plugins en thema's</option>
        @foreach ($allComponents as $component)
          <option value="{{ $component['key'] }}" @selected($filters['component'] === $component['key'])>{{ $component['name'] }} ({{ $component['sites'] }})</option>
        @endforeach
      </select>
      <select name="site" data-autosubmit aria-label="Website">
        <option value="">Alle websites</option>
        @foreach ($sites as $site)
          <option value="{{ $site->site_id }}" @selected($filters['site'] === $site->site_id)>{{ $site->name }}</option>
        @endforeach
      </select>
      <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Zoek plugin, thema of site…" aria-label="Zoeken">
      @if (array_filter($filters))<a class="btn ghost small" href="{{ route('updates.index') }}">Wis filters</a>@endif
    </form>
  </div>

  @if ($filters['component'] === '' && $components->isNotEmpty())
    <section class="panel">
      <div class="panel-head"><h3>Per plugin en thema</h3><span class="hint">Eén klik werkt een component bij op alle sites in de huidige selectie.</span></div>
      <div class="comp-cards">
        @foreach ($components as $component)
          <div class="comp-card">
            <div class="comp-card-head">
              <a class="name" href="{{ route('updates.index', $query(['component' => $component['key']])) }}">{{ $component['name'] }}</a>
              @if (isset($security[$component['type'] . ':' . strtolower($component['slug'])]))<span class="badge sec">beveiliging</span>@endif
            </div>
            <div class="sub">{{ $types[$component['type']] }} · {{ $component['sites'] }} {{ $component['sites'] === 1 ? 'site' : 'sites' }} · naar <span class="ver-to">{{ $component['to'] }}</span></div>
            <div class="sub ver" title="Huidige versies">{{ implode(', ', array_slice($component['from'], 0, 4)) }}{{ count($component['from']) > 4 ? ' …' : '' }}</div>
            @can('manage-sites')
              @if ($component['items'] !== [])
                <form method="POST" action="{{ route('updates.store') }}" data-confirm="{{ $component['name'] }} bijwerken op {{ count($component['items']) }} site(s)?{{ $component['type'] === 'core' ? ' Voor WordPress zelf is er geen automatische rollback.' : '' }}">
                  @csrf
                  @foreach ($component['items'] as $key)<input type="hidden" name="items[]" value="{{ $key }}">@endforeach
                  <button class="btn small block-sm" type="submit">Bijwerken op {{ count($component['items']) }} site{{ count($component['items']) === 1 ? '' : 's' }}</button>
                </form>
              @else
                <span class="btn small done block-sm" role="status">@include('partials.icon', ['name' => 'check', 'size' => 14]) In wachtrij of geblokkeerd</span>
              @endif
            @endcan
          </div>
        @endforeach
      </div>
    </section>
  @endif

  <section class="panel">
    <form method="POST" action="{{ route('updates.store') }}" data-confirm="De geselecteerde updates uitvoeren?">
      @csrf
      <div class="panel-head">
        <h3>Updates per site</h3><span class="badge">{{ $rows->count() }}</span>
        @can('manage-sites')
          <div class="right">
            <span class="hint"><span data-check-count="upd">0</span> geselecteerd</span>
            <button class="btn small" type="submit" data-check-submit="upd" disabled>Geselecteerde bijwerken</button>
          </div>
        @endcan
      </div>
      <div class="table-wrap scroll-lg">
        <table>
          <thead>
            <tr>
              @can('manage-sites')<th class="check-col"><input type="checkbox" data-check-all="upd" aria-label="Alles selecteren"></th>@endcan
              <th>Site</th><th>Component</th><th>Type</th><th>Versie</th><th>Actief</th><th>Status</th>@can('manage-sites')<th></th>@endcan
            </tr>
          </thead>
          <tbody>
            @forelse ($rows as $row)
              <tr>
                @can('manage-sites')
                  <td class="check-col">
                    @if (!$row['queued'] && $row['blocked'] === null)
                      <input type="checkbox" name="items[]" value="{{ $row['key'] }}" data-check="upd" aria-label="{{ $row['name'] }} op {{ $row['site']->name }}">
                    @endif
                  </td>
                @endcan
                <td><a href="{{ route('sites.show', $row['site']) }}"><b>{{ $row['site']->name }}</b></a>@foreach ($row['site']->pinnedNotes as $note)<div class="site-note" title="Vastgezette notitie">@include('partials.icon', ['name' => 'pin', 'size' => 12]) {{ \Illuminate\Support\Str::limit($note->body, 70) }}</div>@endforeach</td>
                <td>{{ $row['name'] }} @if ($row['type'] !== 'core')<span class="muted mono">{{ $row['slug'] }}</span>@endif</td>
                <td class="muted">{{ $types[$row['type']] }}</td>
                <td><span class="ver">{{ $row['from'] }}</span> → <span class="ver-to">{{ $row['to'] }}</span></td>
                <td>{{ $row['active'] ? 'ja' : 'nee' }}</td>
                <td class="wrap">
                  @if ($row['queued'])
                    <span class="state idle">in wachtrij</span>
                  @elseif ($row['blocked'])
                    <span class="note" title="{{ $row['blocked'] }}">{{ \Illuminate\Support\Str::limit($row['blocked'], 70) }}</span>
                  @endif
                </td>
                @can('manage-sites')
                  <td class="actions">
                    @if (!$row['queued'] && $row['blocked'] === null)
                      <button class="btn small quiet" type="submit" form="upd-row-{{ $loop->index }}">Bijwerken</button>
                    @endif
                  </td>
                @endcan
              </tr>
            @empty
              <tr><td colspan="8" class="muted">{{ array_filter($filters) ? 'Geen updates die aan het filter voldoen.' : 'Alle sites zijn bijgewerkt.' }}</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </form>
    @can('manage-sites')
      {{-- Losse formulieren voor de knop per rij (formulieren kunnen niet genest worden). --}}
      @foreach ($rows as $row)
        @if (!$row['queued'] && $row['blocked'] === null)
          <form id="upd-row-{{ $loop->index }}" method="POST" action="{{ route('updates.store') }}" class="hidden" data-confirm="{{ $row['name'] }} {{ $row['to'] }} bijwerken op {{ $row['site']->name }}?{{ $row['type'] === 'core' ? ' Voor WordPress zelf is er geen automatische rollback.' : '' }}">
            @csrf<input type="hidden" name="items[]" value="{{ $row['key'] }}">
          </form>
        @endif
      @endforeach
    @endcan
    <p class="note mt">Updates komen altijd uit wat WordPress op de site zelf aanbiedt (wordpress.org of de updater van een premium-plugin). Na elke update volgt automatisch een nieuwe inventaris.</p>
  </section>

  <section class="panel">
    <div class="panel-head"><h3>Recente update-opdrachten</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tijd</th><th>Site</th><th>Opdracht</th><th>Resultaat</th><th>Door</th></tr></thead>
        <tbody>
          @forelse ($jobs as $job)
            <tr>
              <td>{{ $job->created_at->timezone('Europe/Amsterdam')->format('d-m H:i') }}</td>
              <td>@if ($job->site)<a href="{{ route('sites.show', $job->site) }}">{{ $job->site->name }}</a>@endif</td>
              <td class="mono">{{ $job->command }}</td>
              <td class="wrap">@include('partials.job-result', ['job' => $job])</td>
              <td>{{ $job->requester?->name ?? 'systeem' }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="muted">Nog geen updates uitgevoerd vanuit het panel.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
@endsection
