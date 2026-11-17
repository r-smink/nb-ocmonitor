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
      <span class="hint">{{ $rows->count() === 1 ? '1 lek' : $rows->count() . ' lekken' }} in {{ $groups->count() }} onderde(e)l(en) · {{ $affectedSites }} site(s) getroffen{{ !empty($feed['synced_at']) ? ' · feed bijgewerkt ' . \Carbon\Carbon::parse($feed['synced_at'])->diffForHumans() : '' }}</span>
      <div class="right"><a class="btn ghost small" href="{{ route('updates.index', array_filter(['tag' => request('tag')])) }}">Naar updates</a></div>
    </div>
    <div class="vuln-list">
      @forelse ($groups as $r)
        @php($v = $r['vulnerability'])
        <div class="vuln {{ $r['level'] }}">
          <span @class(['cvss', 'crit' => $r['level'] === 'crit', 'warn' => $r['level'] === 'warn']) title="Hoogste CVSS-score">{{ $v->cvss_score !== null ? number_format($v->cvss_score, 1) : ($v->informational ? 'info' : '?') }}</span>
          <div class="vuln-body">
            <div class="task-meta">
              <a class="task-site" href="{{ route('sites.show', $r['site']) }}">{{ $r['site']->name }}</a>
              <span class="badge">{{ $r['component']['name'] }} <span class="ver">{{ $r['component']['version'] }}</span></span>
              @if ($r['component']['type'] !== 'core')<span class="hint">{{ ['plugin' => 'plugin', 'theme' => 'thema'][$r['component']['type']] ?? '' }}</span>@endif
              @if ($r['items']->count() > 1)<span class="chip warn">{{ $r['items']->count() }} lekken</span>@endif
            </div>
            @if ($r['items']->count() === 1)
              <div class="task-text"><a href="{{ $v->url() }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($v->title ?? 'Kwetsbaarheid', 160) }}</a>@if ($v->cve) <span class="mono">{{ $v->cve }}</span>@endif</div>
            @else
              <ul class="vuln-items">
                @foreach ($r['items'] as $item)
                  @php($iv = $item['vulnerability'])
                  <li class="task-text">
                    <span @class(['cvss-mini', 'crit' => $item['level'] === 'crit', 'warn' => $item['level'] === 'warn']) title="CVSS-score">{{ $iv->cvss_score !== null ? number_format($iv->cvss_score, 1) : ($iv->informational ? 'info' : '?') }}</span>
                    <a href="{{ $iv->url() }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($iv->title ?? 'Kwetsbaarheid', 160) }}</a>@if ($iv->cve) <span class="mono">{{ $iv->cve }}</span>@endif
                    @if (!$item['fixed'])<span class="state bad">geen oplossing</span>@endif
                  </li>
                @endforeach
              </ul>
            @endif
          </div>
          <div class="vuln-fix">
            @if ($r['fix_to'])
              <span class="hint">{{ $r['items']->count() > 1 && $r['unpatched'] === 0 ? 'alles opgelost in' : 'opgelost in' }}</span> <span class="ver-to">{{ $r['fix_to'] }}</span>
              @if ($r['unpatched'] > 0)<br><span class="state bad">{{ $r['unpatched'] }} nog zonder oplossing</span>@endif
            @else
              <span class="state bad">nog geen oplossing</span>
            @endif
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
