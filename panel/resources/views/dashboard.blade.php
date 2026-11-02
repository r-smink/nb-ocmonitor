@extends('layouts.app')
@section('title', 'Overzicht')
@section('content')
  <div class="page-head">
    <div>
      <h1>Overzicht</h1>
      <p>Alle aandachtspunten van alle sites, de belangrijkste bovenaan.</p>
    </div>
    <div class="right summary">
      <span class="chip">{{ $sites->count() }} sites</span>
      <span @class(['chip', 'crit', 'has' => $levels['crit'] > 0])>{{ $levels['crit'] }} kritiek</span>
      <span @class(['chip', 'warn', 'has' => $levels['warn'] > 0])>{{ $levels['warn'] }} aandacht</span>
      <span class="chip ok">{{ $levels['ok'] }} in orde</span>
      @if ($levels['pending'] > 0)<span class="chip">{{ $levels['pending'] }} niet gekoppeld</span>@endif
    </div>
  </div>

  <div class="stats">
    <a href="{{ route('dashboard', ['cat' => 'beveiliging']) }}" @class(['stat', 'lvl-crit' => $issueCounts['security_crit'] > 0, 'lvl-warn' => $issueCounts['security'] > 0 && $issueCounts['security_crit'] === 0])>
      <span>Beveiligingsmeldingen</span>
      <b>{{ $issueCounts['security'] }}</b>
      <small>kritiek en aandacht, laatste {{ \App\Ocm\SiteIssues::EVENT_WINDOW_DAYS }} dagen</small>
    </a>
    <a href="{{ route('updates.index') }}" @class(['stat', 'lvl-warn' => $updateCounts['plugin'] + $updateCounts['theme'] + $updateCounts['core'] > 0])>
      <span>Openstaande updates</span>
      <b>{{ $updateCounts['plugin'] + $updateCounts['theme'] + $updateCounts['core'] }}</b>
      <small>{{ $updateCounts['plugin'] }} plugins · {{ $updateCounts['theme'] }} thema's · {{ $updateCounts['core'] }} WordPress, op {{ $updateCounts['sites'] }} sites</small>
    </a>
    <a href="{{ route('sites.index') }}" @class(['stat', 'lvl-crit' => $levels['crit'] > 0])>
      <span>Sites met problemen</span>
      <b>{{ $levels['crit'] + $levels['warn'] }}</b>
      <small>{{ $levels['crit'] }} kritiek · {{ $levels['warn'] }} aandacht · {{ $levels['ok'] }} in orde</small>
    </a>
    <a href="{{ route('pagespeed.index') }}" @class(['stat', 'lvl-warn' => $avgPsi !== null && $avgPsi < 50])>
      <span>PageSpeed mobiel (gemiddeld)</span>
      <b>{{ $avgPsi ?? '–' }}</b>
      <small>{{ $avgPsi === null ? 'Nog geen metingen' : 'laatste meting per site' }}</small>
    </a>
  </div>

  <section class="panel">
    <div class="panel-head">
      <h3>Aandachtspunten</h3>
      <span class="hint">{{ $issueCounts['crit'] }} kritiek · {{ $issueCounts['warn'] }} aandacht · {{ $issueCounts['info'] }} info</span>
      <div class="right">
        <nav class="tabs-nav">
          <a href="{{ route('dashboard', array_filter(['info' => $showInfo ? 1 : null])) }}" @class(['on' => $category === null])>Alles</a>
          @foreach (\App\Ocm\SiteIssues::CATEGORIES as $key => $label)
            <a href="{{ route('dashboard', array_filter(['cat' => $key, 'info' => $showInfo ? 1 : null])) }}" @class(['on' => $category === $key])>{{ $label }}@if (($issueCounts['per_category'][$key] ?? 0) > 0) ({{ $issueCounts['per_category'][$key] }})@endif</a>
          @endforeach
        </nav>
        <a class="btn ghost small" href="{{ route('dashboard', array_filter(['cat' => $category, 'info' => $showInfo ? null : 1])) }}">{{ $showInfo ? 'Verberg info' : 'Toon ook info' }}</a>
      </div>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Site</th><th>Categorie</th><th>Melding</th></tr></thead>
        <tbody>
          @forelse ($issues as $issue)
            <tr data-href="{{ route('sites.show', $issue['site']) }}">
              <td><span class="dcell lvl-{{ $issue['level'] }}"><span class="dot"></span><a href="{{ route('sites.show', $issue['site']) }}"><b>{{ $issue['site']->name }}</b></a></span></td>
              <td><span class="badge">{{ \App\Ocm\SiteIssues::CATEGORIES[$issue['category']] }}</span></td>
              <td @class(['wrap', 'issue-text', $issue['level']])>{{ $issue['text'] }}</td>
            </tr>
          @empty
            <tr><td colspan="3" class="muted">{{ $sites->isEmpty() ? 'Nog geen sites.' : 'Geen aandachtspunten. Alles is in orde.' }}</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>

  <div class="cols">
    <section class="panel">
      <div class="panel-head"><h3>Meest voorkomende updates</h3><a class="right" href="{{ route('updates.index') }}">Alle updates →</a></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Component</th><th>Type</th><th class="num">Sites</th><th>Nieuwste</th></tr></thead>
          <tbody>
            @forelse ($topComponents as $component)
              <tr data-href="{{ route('updates.index', ['component' => $component['type'] . ':' . $component['slug']]) }}">
                <td><a href="{{ route('updates.index', ['component' => $component['type'] . ':' . $component['slug']]) }}"><b>{{ $component['name'] }}</b></a></td>
                <td class="muted">{{ \App\Http\Controllers\UpdateController::TYPES[$component['type']] }}</td>
                <td class="num">{{ $component['sites'] }}</td>
                <td class="ver-to">{{ $component['to'] }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="muted">Alles is bijgewerkt.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h3>Laagste PageSpeed (mobiel)</h3><a class="right" href="{{ route('pagespeed.index') }}">Alle scores →</a></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Site</th><th>Mobiel</th><th>Desktop</th><th>Gemeten</th></tr></thead>
          <tbody>
            @forelse ($worstPsi as $row)
              <tr data-href="{{ route('sites.show', $row['site']) }}">
                <td><a href="{{ route('sites.show', $row['site']) }}"><b>{{ $row['site']->name }}</b></a></td>
                <td>@include('partials.score', ['value' => $row['mobile']->performance])</td>
                <td>@include('partials.score', ['value' => $row['desktop']?->performance])</td>
                <td class="muted">{{ $row['mobile']->measured_at->diffForHumans() }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="muted">Nog geen PageSpeed-metingen.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <section class="panel">
    <div class="panel-head"><h3>Recente wijzigingen</h3><span class="hint">laatste 48 uur, op alle sites</span><a class="right" href="{{ route('activity.index') }}">Alle activiteit →</a></div>
    @include('partials.events-table', ['events' => $events, 'showSite' => true])
  </section>
@endsection
