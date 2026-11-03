@extends('layouts.app')
@section('title', 'Overzicht')
@section('content')
  @php($catIcons = ['beschikbaarheid' => 'alert', 'beveiliging' => 'shield', 'updates' => 'refresh', 'prestaties' => 'gauge', 'koppeling' => 'link'])
  @php($updateTotal = $updateCounts['plugin'] + $updateCounts['theme'] + $updateCounts['core'])
  <div class="page-head">
    <div>
      <h1>Overzicht</h1>
      <p>Alles wat vandaag je aandacht vraagt, de zwaarste bovenaan.</p>
    </div>
    <div class="right summary">
      <span class="chip">{{ $sites->count() }} sites</span>
      <span @class(['chip', 'crit', 'has' => $levels['crit'] > 0])>{{ $levels['crit'] }} kritiek</span>
      <span @class(['chip', 'warn', 'has' => $levels['warn'] > 0])>{{ $levels['warn'] }} aandacht</span>
      <span class="chip ok">{{ $levels['ok'] }} in orde</span>
      @if ($levels['pending'] > 0)<span class="chip">{{ $levels['pending'] }} niet gekoppeld</span>@endif
    </div>
  </div>
  @include('partials.tag-filter')

  <div class="kpis">
    <a href="{{ route('dashboard', ['cat' => 'beveiliging']) }}#aandachtspunten" @class(['kpi', 'lvl-crit' => $issueCounts['security_crit'] > 0, 'lvl-warn' => $issueCounts['security'] > 0 && $issueCounts['security_crit'] === 0])>
      <span class="kpi-head"><span class="kpi-ic">@include('partials.icon', ['name' => 'shield', 'size' => 20])</span>Beveiligingsmeldingen</span>
      <b>{{ $issueCounts['security'] }}</b>
      <small>kritiek en aandacht, laatste {{ \App\Ocm\SiteIssues::EVENT_WINDOW_DAYS }} dagen</small>
    </a>
    <a href="{{ route('updates.index') }}" @class(['kpi', 'lvl-warn' => $updateTotal > 0])>
      <span class="kpi-head"><span class="kpi-ic">@include('partials.icon', ['name' => 'refresh', 'size' => 20])</span>Openstaande updates</span>
      <b>{{ $updateTotal }}</b>
      <small>{{ $updateCounts['plugin'] }} plugins · {{ $updateCounts['theme'] }} thema's · {{ $updateCounts['core'] }} WordPress, op {{ $updateCounts['sites'] }} sites</small>
    </a>
    <a href="{{ route('sites.index') }}" @class(['kpi', 'lvl-crit' => $levels['crit'] > 0, 'lvl-warn' => $levels['crit'] === 0 && $levels['warn'] > 0])>
      <span class="kpi-head"><span class="kpi-ic">@include('partials.icon', ['name' => 'server', 'size' => 20])</span>Sites met problemen</span>
      <b>{{ $levels['crit'] + $levels['warn'] }}</b>
      <small>{{ $levels['crit'] }} kritiek · {{ $levels['warn'] }} aandacht · {{ $levels['ok'] }} in orde</small>
    </a>
    <a href="{{ route('pagespeed.index') }}" @class(['kpi', 'lvl-none' => $avgPsi === null, 'lvl-crit' => $avgPsi !== null && $avgPsi < 50, 'lvl-warn' => $avgPsi !== null && $avgPsi >= 50 && $avgPsi < 90])>
      <span class="kpi-head"><span class="kpi-ic">@include('partials.icon', ['name' => 'gauge', 'size' => 20])</span>PageSpeed mobiel (gem.)</span>
      <b>{{ $avgPsi ?? '–' }}</b>
      <small>{{ $avgPsi === null ? 'Nog geen metingen' : 'laatste meting per site' }}</small>
    </a>
  </div>

  <section class="panel flush" id="aandachtspunten">
    <div class="panel-head">
      <h3 class="big">Aandachtspunten</h3>
      @php($openCount = $issueCounts['crit'] + $issueCounts['warn'])
      <span @class(['count-pill', 'crit' => $issueCounts['crit'] > 0])>{{ $openCount }} open</span>
      <div class="right">
        <nav class="tabs-nav" aria-label="Filter op categorie">
          <a href="{{ route('dashboard', array_filter(['info' => $showInfo ? 1 : null])) }}#aandachtspunten" @class(['on' => $category === null])>Alles</a>
          @foreach (\App\Ocm\SiteIssues::CATEGORIES as $key => $label)
            <a href="{{ route('dashboard', array_filter(['cat' => $key, 'info' => $showInfo ? 1 : null])) }}#aandachtspunten" @class(['on' => $category === $key])>{{ $label }}@if (($issueCounts['per_category'][$key] ?? 0) > 0) ({{ $issueCounts['per_category'][$key] }})@endif</a>
          @endforeach
        </nav>
        <a class="btn ghost small" href="{{ route('dashboard', array_filter(['cat' => $category, 'info' => $showInfo ? null : 1])) }}#aandachtspunten">{{ $showInfo ? 'Verberg info' : 'Toon ook info' }}</a>
      </div>
    </div>
    <div class="tasks">
      @php($groups = ['crit' => 'Nu oplossen', 'warn' => 'Deze week', 'info' => 'Ter info'])
      @forelse ($issues->groupBy('level')->sortBy(fn ($g, $level) => \App\Ocm\SiteIssues::LEVEL_RANK[$level]) as $level => $group)
        <div class="task-group {{ $level }}">{{ $groups[$level] }} <span class="hint">({{ $group->count() }})</span></div>
        @foreach ($group as $issue)
          <div class="task {{ $level }}">
            <span class="task-ic">@include('partials.icon', ['name' => $catIcons[$issue['category']] ?? 'alert', 'size' => 17])</span>
            <div class="task-body">
              <div class="task-meta">
                <span class="task-cat">{{ \App\Ocm\SiteIssues::CATEGORIES[$issue['category']] }}</span>
                <a class="task-site" href="{{ route('sites.show', $issue['site']) }}">{{ $issue['site']->name }}</a>
              </div>
              <div class="task-text">{{ $issue['text'] }}</div>
            </div>
            <div class="task-actions">
              @include('partials.row-action', ['action' => $issue['action'], 'primary' => $level === 'crit'])
            </div>
          </div>
        @endforeach
      @empty
        <div class="empty">{{ $sites->isEmpty() ? 'Nog geen sites.' : 'Geen aandachtspunten. Alles is in orde.' }}</div>
      @endforelse
    </div>
  </section>

  <div class="widgets">
    <section class="panel">
      <div class="panel-head"><h3>Meest voorkomende updates</h3><a class="more" href="{{ route('updates.index') }}">Alle updates →</a></div>
      <div class="mini-list">
        @forelse ($topComponents->take(6) as $component)
          <div class="mini-row">
            <div class="grow">
              <a class="name" href="{{ route('updates.index', ['component' => $component['type'] . ':' . $component['slug']]) }}">{{ $component['name'] }}</a>
              <span class="sub">{{ \App\Http\Controllers\UpdateController::TYPES[$component['type']] }} · {{ $component['sites'] }} {{ $component['sites'] === 1 ? 'site' : 'sites' }}</span>
            </div>
            <span class="ver-to">{{ $component['to'] }}</span>
            @can('manage-sites')
              @if ($component['items'] !== [])
                @include('partials.row-action', ['primary' => true, 'action' => [
                  'kind' => 'form', 'label' => 'Bijwerken', 'action' => route('updates.store'), 'fields' => ['items' => $component['items']],
                  'confirm' => $component['name'] . ' bijwerken naar ' . $component['to'] . ' op ' . count($component['items']) . ' site(s)?' . ($component['type'] === 'core' ? ' Voor WordPress zelf is er geen automatische rollback.' : ''),
                ]])
              @elseif ($component['queued'] > 0)
                @include('partials.row-action', ['action' => ['kind' => 'done', 'label' => 'In wachtrij']])
              @endif
            @endcan
          </div>
        @empty
          <p class="muted">Alles is bijgewerkt.</p>
        @endforelse
      </div>
    </section>

    @if (\App\Ocm\Menu::enabled('pagespeed'))
    <section class="panel">
      <div class="panel-head"><h3>Laagste PageSpeed (mobiel)</h3><a class="more" href="{{ route('pagespeed.index') }}">Alle scores →</a></div>
      <div class="mini-list">
        @forelse ($worstPsi as $row)
          <div class="mini-row">
            <div class="grow">
              <a class="name" href="{{ route('sites.show', $row['site']) }}">{{ $row['site']->name }}</a>
              <span class="sub">{{ $row['mobile']->measured_at->diffForHumans() }}</span>
            </div>
            <span title="Mobiel">@include('partials.score', ['value' => $row['mobile']->performance])</span>
            <span title="Desktop">@include('partials.score', ['value' => $row['desktop']?->performance])</span>
          </div>
        @empty
          <p class="muted">Nog geen PageSpeed-metingen.</p>
        @endforelse
      </div>
    </section>
    @endif

    <section class="panel">
      <div class="panel-head"><h3>Recente wijzigingen</h3><a class="more" href="{{ route('activity.index') }}">Alle activiteit →</a></div>
      @php($eventLabels = ['plugin.update' => 'Plugin bijgewerkt', 'theme.update' => 'Thema bijgewerkt', 'core.update' => 'WordPress bijgewerkt', 'plugin.install' => 'Plugin geïnstalleerd', 'plugin.activate' => 'Plugin geactiveerd', 'plugin.deactivate' => 'Plugin gedeactiveerd', 'plugin.delete' => 'Plugin verwijderd', 'theme.switch' => 'Ander thema', 'user.created' => 'Gebruiker aangemaakt', 'user.role_changed' => 'Rol gewijzigd', 'option.changed' => 'Instelling gewijzigd', 'plugin.rolled_back' => 'Plugin teruggedraaid', 'theme.rolled_back' => 'Thema teruggedraaid'])
      <ul class="feed">
        @forelse ($events->take(8) as $event)
          <li>
            <span @class(['fdot', 'crit' => $event->result === 'failed', 'warn' => $event->result !== 'failed' && $event->source === 'unknown', 'ok' => $event->result !== 'failed' && $event->source !== 'unknown'])></span>
            <span class="t">{{ $event->occurred_at->timezone('Europe/Amsterdam')->isToday() ? $event->occurred_at->timezone('Europe/Amsterdam')->format('H:i') : $event->occurred_at->timezone('Europe/Amsterdam')->format('d-m H:i') }}</span>
            <span class="grow" title="{{ $event->type }} {{ $event->object }}"><a href="{{ route('sites.show', $event->site) }}">{{ $event->site->name }}</a> · {{ $eventLabels[$event->type] ?? $event->type }}{{ $event->object ? ': ' . \Illuminate\Support\Str::limit(\Illuminate\Support\Str::before($event->object, '/') ?: $event->object, 40) : '' }}@if ($event->from_version && $event->to_version) {{ $event->from_version }} → {{ $event->to_version }}@endif{{ $event->result === 'failed' ? ' (mislukt)' : '' }}</span>
          </li>
        @empty
          <li class="muted">Geen wijzigingen in de laatste 48 uur.</li>
        @endforelse
      </ul>
    </section>
  </div>
@endsection
