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
      <p>Beschikbare updates op alle gekoppelde sites. Per plugin of thema maakt de site eerst een kopie, en na de update volgt een health check met automatische rollback.</p>
    </div>
    <div class="right summary">
      <span @class(['chip', 'warn', 'has' => $counts['plugin'] > 0])>{{ $counts['plugin'] }} plugins</span>
      <span @class(['chip', 'warn', 'has' => $counts['theme'] > 0])>{{ $counts['theme'] }} thema's</span>
      <span @class(['chip', 'crit', 'has' => $counts['core'] > 0])>{{ $counts['core'] }} WordPress</span>
    </div>
  </div>

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
      <div class="table-wrap scroll-sm">
        <table>
          <thead><tr><th>Component</th><th>Type</th><th class="num">Sites</th><th>Huidige versies</th><th>Nieuwste</th>@can('manage-sites')<th></th>@endcan</tr></thead>
          <tbody>
            @foreach ($components as $component)
              <tr>
                <td><a href="{{ route('updates.index', $query(['component' => $component['key']])) }}"><b>{{ $component['name'] }}</b></a> <span class="muted mono">{{ $component['slug'] }}</span></td>
                <td class="muted">{{ $types[$component['type']] }}</td>
                <td class="num">{{ $component['sites'] }}</td>
                <td class="ver wrap">{{ implode(', ', array_slice($component['from'], 0, 5)) }}{{ count($component['from']) > 5 ? ' …' : '' }}</td>
                <td class="ver-to">{{ $component['to'] }}</td>
                @can('manage-sites')
                  <td class="num">
                    @if ($component['items'] !== [])
                      <form method="POST" action="{{ route('updates.store') }}" class="inline" data-confirm="{{ $component['name'] }} bijwerken op {{ count($component['items']) }} site(s)?">
                        @csrf
                        @foreach ($component['items'] as $key)<input type="hidden" name="items[]" value="{{ $key }}">@endforeach
                        <button class="btn small" type="submit">Bijwerken op {{ count($component['items']) }} site{{ count($component['items']) === 1 ? '' : 's' }}</button>
                      </form>
                    @else
                      <span class="state idle">in wachtrij of geblokkeerd</span>
                    @endif
                  </td>
                @endcan
              </tr>
            @endforeach
          </tbody>
        </table>
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
              <th>Site</th><th>Component</th><th>Type</th><th>Versie</th><th>Actief</th><th>Status</th>
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
                <td><a href="{{ route('sites.show', $row['site']) }}"><b>{{ $row['site']->name }}</b></a></td>
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
              </tr>
            @empty
              <tr><td colspan="7" class="muted">{{ array_filter($filters) ? 'Geen updates die aan het filter voldoen.' : 'Alle sites zijn bijgewerkt.' }}</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </form>
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
