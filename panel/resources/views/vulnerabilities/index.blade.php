@extends('layouts.app')
@section('title', 'Kwetsbaarheden')
@section('content')
  <div class="page-head">
    <div>
      <h1>Kwetsbaarheden</h1>
      <p>Bekende lekken in de geïnstalleerde versies van plugins, thema's en WordPress, de ernstigste bovenaan. De feed wordt elke nacht bijgewerkt.</p>
    </div>
    <div class="right summary">
      <a href="{{ route('vulnerabilities.index', array_filter(['niveau' => 'crit', 'tag' => request('tag')])) }}" @class(['chip', 'chip-link', 'crit', 'has' => $counts['crit'] > 0])>{{ $counts['crit'] }} hoog</a>
      <a href="{{ route('vulnerabilities.index', array_filter(['niveau' => 'warn', 'tag' => request('tag')])) }}" @class(['chip', 'chip-link', 'warn', 'has' => $counts['warn'] > 0])>{{ $counts['warn'] }} gemiddeld</a>
      <a href="{{ route('vulnerabilities.index', array_filter(['niveau' => 'info', 'tag' => request('tag')])) }}" class="chip chip-link">{{ $counts['info'] }} laag</a>
      @if ($level)<a class="btn ghost small" href="{{ route('vulnerabilities.index', array_filter(['tag' => request('tag')])) }}">Alles</a>@endif
    </div>
  </div>
  @include('partials.tag-filter')

  @if (!$enabled)
    <div class="alert warn">Zet een gratis API-sleutel van Wordfence in .env als <code>OCM_WORDFENCE_API_KEY</code> (wordfence.com → Account → Integrations) en voer <code>docker compose exec scheduler php artisan ocm:vulnerabilities</code> uit.</div>
  @elseif (!empty($feed['error']))
    <div class="alert warn">Laatste update van de feed mislukt: {{ $feed['error'] }}</div>
  @endif

  <section class="panel">
    <div class="panel-head">
      <h3>Gerangschikt op ernst</h3><span class="badge">{{ $rows->count() }}</span>
      <span class="hint">{{ $affectedSites }} site(s) getroffen{{ !empty($feed['synced_at']) ? ' · feed bijgewerkt ' . \Carbon\Carbon::parse($feed['synced_at'])->diffForHumans() : '' }}</span>
      <div class="right"><a class="btn ghost small" href="{{ route('updates.index', array_filter(['tag' => request('tag')])) }}">Naar updates</a></div>
    </div>
    <div class="vuln-list">
      @forelse ($rows as $r)
        @php($v = $r['vulnerability'])
        <div class="vuln {{ $r['level'] }}">
          <span @class(['cvss', 'crit' => $r['level'] === 'crit', 'warn' => $r['level'] === 'warn']) title="CVSS-score">{{ $v->cvss_score !== null ? number_format($v->cvss_score, 1) : ($v->informational ? 'info' : '?') }}</span>
          <div class="vuln-body">
            <div class="task-meta">
              <a class="task-site" href="{{ route('sites.show', $r['site']) }}">{{ $r['site']->name }}</a>
              <span class="badge">{{ $r['component']['name'] }} <span class="ver">{{ $r['component']['version'] }}</span></span>
              @if ($r['component']['type'] !== 'core')<span class="hint">{{ ['plugin' => 'plugin', 'theme' => 'thema'][$r['component']['type']] ?? '' }}</span>@endif
            </div>
            <div class="task-text"><a href="{{ $v->url() }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($v->title ?? 'Kwetsbaarheid', 160) }}</a>@if ($v->cve) <span class="mono">{{ $v->cve }}</span>@endif</div>
          </div>
          <div class="vuln-fix">
            @if ($r['fixed'])<span class="hint">opgelost in</span> <span class="ver-to">{{ $r['fixed'] }}</span>@else<span class="state bad">nog geen oplossing</span>@endif
          </div>
          <div class="task-actions">
            @if ($r['action'])
              @include('partials.row-action', ['action' => $r['action'], 'primary' => $r['level'] === 'crit'])
            @endif
          </div>
        </div>
      @empty
        <div class="empty">{{ $enabled ? 'Geen bekende kwetsbaarheden in de geïnstalleerde versies.' : 'Nog geen gegevens.' }}</div>
      @endforelse
    </div>
    <p class="note mt">{{ \App\Ocm\Vulnerabilities::ATTRIBUTION }} <a href="https://www.wordfence.com/threat-intel/" target="_blank" rel="noopener noreferrer">wordfence.com/threat-intel</a></p>
  </section>
@endsection
