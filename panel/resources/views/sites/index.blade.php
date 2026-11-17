@extends('layouts.app')
@section('title', 'Sites')
@section('content')
  @php
    // Huidige filters behouden bij een klik op een pill of de weergave.
    $query = fn (array $extra) => array_filter(array_merge(['q' => $q, 'tag' => request('tag'), 'indeling' => $layout === 'lijst' ? null : $layout, 'status' => $status], $extra), fn ($v) => $v !== null && $v !== '');
    $levelLabels = ['crit' => 'Kritiek', 'warn' => 'Aandacht', 'ok' => 'In orde', 'pending' => 'Niet gekoppeld'];
  @endphp
  <div class="page-head">
    <div>
      <h1>Sites</h1>
      <p>Gekoppelde WordPress-sites, problemen bovenaan. Klik op een teller om te filteren.</p>
    </div>
  </div>

  <div class="toolbar">
    <div class="summary">
      <a href="{{ route('sites.index', $query(['status' => null])) }}" @class(['chip', 'chip-link', 'on' => $status === null])>{{ $total }} sites</a>
      <a href="{{ route('sites.index', $query(['status' => $status === 'crit' ? null : 'crit'])) }}" @class(['chip', 'chip-link', 'crit', 'has' => $counts['crit'] > 0, 'on' => $status === 'crit'])>{{ $counts['crit'] }} kritiek</a>
      <a href="{{ route('sites.index', $query(['status' => $status === 'warn' ? null : 'warn'])) }}" @class(['chip', 'chip-link', 'warn', 'has' => $counts['warn'] > 0, 'on' => $status === 'warn'])>{{ $counts['warn'] }} aandacht</a>
      <a href="{{ route('sites.index', $query(['status' => $status === 'ok' ? null : 'ok'])) }}" @class(['chip', 'chip-link', 'ok', 'on' => $status === 'ok'])>{{ $counts['ok'] }} in orde</a>
      @if ($counts['pending'] > 0)
        <a href="{{ route('sites.index', $query(['status' => $status === 'pending' ? null : 'pending'])) }}" @class(['chip', 'chip-link', 'on' => $status === 'pending'])>{{ $counts['pending'] }} niet gekoppeld</a>
      @endif
    </div>
    <form class="filters" method="GET" action="{{ route('sites.index') }}">
      @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
      @include('partials.tag-select')
      <select name="indeling" data-autosubmit aria-label="Indeling">
        <option value="lijst" @selected($layout === 'lijst')>Eén lijst</option>
        <option value="klant" @selected($layout === 'klant')>Per klant</option>
        <option value="server" @selected($layout === 'server')>Per server</option>
        <option value="tag" @selected($layout === 'tag')>Per tag</option>
      </select>
      <input type="search" name="q" value="{{ $q }}" placeholder="Zoek site, klant of server…" aria-label="Zoeken">
      <div class="view-toggle" role="group" aria-label="Weergave">
        <a href="{{ route('sites.index', $query(['weergave' => 'kaarten'])) }}" @class(['on' => $view === 'kaarten']) @if ($view === 'kaarten') aria-current="true" @endif>Kaarten</a>
        <a href="{{ route('sites.index', $query(['weergave' => 'lijst'])) }}" @class(['on' => $view === 'lijst']) @if ($view === 'lijst') aria-current="true" @endif>Lijst</a>
      </div>
    </form>
  </div>

  @if ($status !== null || $q !== '')
    <p class="filter-note">{{ $sites->count() }} van {{ $total }} sites{{ $status ? ' · ' . mb_strtolower($levelLabels[$status]) : '' }}{{ $q !== '' ? ' · zoekterm "' . $q . '"' : '' }}
      · <a href="{{ route('sites.index', array_filter(['tag' => request('tag'), 'indeling' => $layout === 'lijst' ? null : $layout])) }}">filter wissen</a></p>
  @endif

  @foreach ($groups as $groupName => $groupSites)
  @if ($groupName !== '')<h3 class="group-head">{{ $groupName }} <span class="badge">{{ count($groupSites) }}</span></h3>@endif
  @if ($view === 'lijst')
    <section class="panel site-list-panel">
      <div class="table-wrap">
        <table class="site-list">
          <thead><tr><th>Site</th><th>Klant</th><th>Server</th><th>WordPress</th><th>PHP</th><th class="num">Updates</th><th>Probleem</th><th>Laatst gezien</th></tr></thead>
          <tbody>
            @forelse ($groupSites as $site)
              @php($level = $site->level())
              @php($issues = collect($site->issues())->whereIn('level', ['crit', 'warn'])->values())
              <tr data-href="{{ route('sites.show', $site) }}" class="lvl-{{ $level }}">
                <td class="site-list-name">
                  <span class="sdot" title="{{ $levelLabels[$level] ?? $level }}"></span>
                  <a href="{{ route('sites.show', $site) }}">{{ $site->name }}</a>
                  <span class="muted">{{ $site->host }}</span>
                </td>
                <td>{{ $site->customer?->name ?? $site->client_name ?? '' }}</td>
                <td class="muted">{{ $site->server }}</td>
                <td class="ver">{{ $site->wp_version ?? '–' }}</td>
                <td class="ver">{{ $site->php_version ?? '–' }}</td>
                <td class="num"><b @class(['warn' => $site->updates_count > 0])>{{ $site->isPaired() ? ($site->updates_count ?: '') : '–' }}</b></td>
                <td class="site-list-issue">
                  @if ($issues->isNotEmpty())
                    <span class="site-problem-inline {{ $issues->first()['level'] }}">{{ \Illuminate\Support\Str::limit($issues->first()['text'], 90) }}</span>@if ($issues->count() > 1) <span class="more-n">+{{ $issues->count() - 1 }}</span>@endif
                  @elseif (!$site->isPaired())
                    <span class="muted">Nog niet gekoppeld</span>
                  @endif
                </td>
                <td class="muted nowrap">{{ $site->last_seen_at ? $site->last_seen_at->diffForHumans(null, true) : '–' }}</td>
              </tr>
            @empty
              <tr><td colspan="8" class="muted">Geen sites{{ $status || $q !== '' ? ' met dit filter' : '' }}.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  @else
  <div class="cards">
    @forelse ($groupSites as $site)
      @php($level = $site->level())
      @php($issues = collect($site->issues())->whereIn('level', ['crit', 'warn'])->values())
      <a href="{{ route('sites.show', $site) }}" class="site-card lvl-{{ $level }}">
        <div class="site-card-head">
          <span class="sdot"></span>
          <span class="site-card-name">{{ $site->name }}</span>
          @if ($site->server)<span class="badge server" title="Server">{{ $site->server }}</span>@endif
        </div>
        <div class="tile-host">{{ $site->host }}@if ($site->customer?->name ?? $site->client_name) · {{ $site->customer?->name ?? $site->client_name }}@endif</div>
        @include('partials.tags', ['tags' => $site->allTags()])
        @if ($issues->isNotEmpty())
          <div class="site-problem {{ $issues->first()['level'] }}">{{ $issues->first()['text'] }}@if ($issues->count() > 1) <span class="more-n">+{{ $issues->count() - 1 }} meer</span>@endif</div>
        @elseif (!$site->isPaired())
          <div class="site-problem info">{{ collect($site->issues())->first()['text'] ?? 'Nog niet gekoppeld' }}</div>
        @endif
        @foreach ($site->pinnedNotes as $note)
          <div class="site-note" title="Vastgezette notitie">@include('partials.icon', ['name' => 'pin', 'size' => 13]) {{ \Illuminate\Support\Str::limit($note->body, 90) }}</div>
        @endforeach
        <div class="site-stats">
          <div><span>WordPress</span><b>{{ $site->wp_version ?? '–' }}</b></div>
          <div><span>PHP</span><b>{{ $site->php_version ?? '–' }}</b></div>
          <div><span>Updates</span><b @class(['warn' => $site->updates_count > 0])>{{ $site->isPaired() ? $site->updates_count : '–' }}</b></div>
          <div><span>Status</span><b>{{ $levelLabels[$level] ?? $level }}</b></div>
        </div>
        <div class="site-card-foot">
          <span>{{ $site->last_seen_at ? 'Laatst gezien ' . $site->last_seen_at->diffForHumans() : 'Nog geen contact' }}</span>
          <span class="open">Openen @include('partials.icon', ['name' => 'arrow', 'size' => 15])</span>
        </div>
      </a>
    @empty
      <div class="empty">
        @if ($status || $q !== '')
          Geen sites met dit filter.
        @else
          Nog geen sites.
          @can('manage-sites') <a href="{{ route('sites.create') }}">Voeg de eerste site toe</a>. @endcan
        @endif
      </div>
    @endforelse
  </div>
  @endif
  @endforeach
@endsection
