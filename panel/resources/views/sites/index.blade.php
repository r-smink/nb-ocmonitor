@extends('layouts.app')
@section('title', 'Sites')
@section('content')
  <div class="page-head">
    <div>
      <h1>Sites</h1>
      <p>Gekoppelde WordPress-sites, problemen bovenaan.</p>
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
  <div class="cards">
    @forelse ($groupSites as $site)
      @php($level = $site->level())
      @php($issues = collect($site->issues())->whereIn('level', ['crit', 'warn'])->values())
      <a href="{{ route('sites.show', $site) }}" class="site-card lvl-{{ $level }}">
        <div class="site-card-head">
          <span class="sdot"></span>
          <span class="site-card-name">{{ $site->name }}</span>
          @if ($site->customer?->name ?? $site->client_name)
            <span class="badge">{{ $site->customer?->name ?? $site->client_name }}</span>
          @endif
        </div>
        <div class="tile-host">{{ $site->host }}</div>
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
          <div><span>Status</span><b>{{ ['crit' => 'Kritiek', 'warn' => 'Aandacht', 'ok' => 'In orde', 'pending' => 'Niet gekoppeld'][$level] ?? $level }}</b></div>
        </div>
        <div class="site-card-foot">
          <span>{{ $site->last_seen_at ? 'Laatst gezien ' . $site->last_seen_at->diffForHumans() : 'Nog geen contact' }}</span>
          <span class="open">Openen @include('partials.icon', ['name' => 'arrow', 'size' => 15])</span>
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
