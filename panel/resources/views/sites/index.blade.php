@extends('layouts.app')
@section('title', 'Sites')
@section('content')
  <div class="page-head">
    <div>
      <h1>Sites</h1>
      <p>Gekoppelde WordPress-sites, met problemen bovenaan.</p>
    </div>
    <div class="right">
      @can('manage-sites')
        <a class="btn" href="{{ route('sites.create') }}">Site toevoegen <span class="arrow" aria-hidden="true">→</span></a>
      @endcan
    </div>
  </div>

  <div class="toolbar">
    <div class="summary">
      <span @class(['chip', 'crit', 'has' => $counts['crit'] > 0])>{{ $counts['crit'] }} kritiek</span>
      <span @class(['chip', 'warn', 'has' => $counts['warn'] > 0])>{{ $counts['warn'] }} aandacht</span>
      <span class="chip ok">{{ $counts['ok'] }} in orde</span>
      @if ($counts['pending'] > 0)
        <span class="chip">{{ $counts['pending'] }} niet gekoppeld</span>
      @endif
    </div>
    <form class="filters" method="GET" action="{{ route('sites.index') }}">
      @include('partials.tag-select')
      <select name="indeling" data-autosubmit aria-label="Indeling">
        <option value="lijst" @selected($layout === 'lijst')>Eén lijst</option>
        <option value="klant" @selected($layout === 'klant')>Per klant</option>
        <option value="tag" @selected($layout === 'tag')>Per tag</option>
      </select>
      <input type="search" name="q" value="{{ $q }}" placeholder="Zoek site of klant…" aria-label="Zoeken">
    </form>
  </div>

  @foreach ($groups as $groupName => $groupSites)
  @if ($groupName !== '')<h3 class="group-head">{{ $groupName }} <span class="badge">{{ count($groupSites) }}</span></h3>@endif
  <div class="grid">
    @forelse ($groupSites as $site)
      <a href="{{ route('sites.show', $site) }}" class="tile lvl-{{ $site->level() }}">
        <div class="tile-head">
          <span class="dot"></span>
          <div>
            <div class="tile-name">{{ $site->name }}</div>
          </div>
          @if ($site->client_name)
            <span class="badge">{{ $site->client_name }}</span>
          @endif
        </div>
        @include('partials.tags', ['tags' => $site->allTags()])
        <div class="tile-host">{{ $site->host }}</div>
        @php($issues = $site->issues())
        @if ($issues !== [])
          <ul class="issues">
            @foreach ($issues as $issue)
              <li class="{{ $issue['level'] }}">{{ $issue['text'] }}</li>
            @endforeach
          </ul>
        @endif
        <div class="site-stats">
          <div><span>WordPress</span><b>{{ $site->wp_version ?? '–' }}</b></div>
          <div><span>PHP</span><b>{{ $site->php_version ?? '–' }}</b></div>
          <div><span>Updates</span><b @class(['warn' => $site->updates_count > 0])>{{ $site->isPaired() ? $site->updates_count : '–' }}</b></div>
          <div><span>Plugin</span><b>{{ $site->plugin_version ?? '–' }}</b></div>
        </div>
        <div class="tile-foot">
          <span>{{ $site->last_seen_at ? 'Laatst gezien ' . $site->last_seen_at->diffForHumans() : 'Nog geen contact' }}</span>
        </div>
      </a>
    @empty
      <div class="empty">
        Nog geen sites.
        @can('manage-sites') <a href="{{ route('sites.create') }}">Voeg de eerste site toe</a>. @endcan
      </div>
    @endforelse
  </div>
  @endforeach
@endsection
