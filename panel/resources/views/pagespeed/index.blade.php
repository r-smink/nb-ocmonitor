@extends('layouts.app')
@section('title', 'PageSpeed')
@section('content')
  <div class="page-head">
    <div>
      <h1>PageSpeed</h1>
      <p>Google PageSpeed Insights voor alle gekoppelde sites, slechtste mobiele score bovenaan. Elke {{ $intervalDays }} dagen automatisch gemeten.</p>
    </div>
  </div>

  @include('partials.tag-filter')

  @unless ($hasKey)
    <div class="alert warn">
      Er is geen API-sleutel ingesteld. De metingen werken wel, maar Google beperkt het aantal sterk.
      Zet <code>OCM_PAGESPEED_API_KEY</code> in <code>.env</code> (Google Cloud → PageSpeed Insights API).
    </div>
  @endunless

  <div class="cards psi-cards">
    @forelse ($rows as $row)
      @php($m = $row['mobile'])
      <div class="site-card psi-card lvl-{{ $m?->performance === null ? 'pending' : \App\Models\PagespeedResult::level($m->performance) }}">
        <div class="site-card-head">
          <a class="site-card-name" href="{{ route('sites.show', $row['site']) }}">{{ $row['site']->name }}</a>
          <span class="hint">{{ $m?->measured_at?->diffForHumans() ?? 'nog niet gemeten' }}</span>
        </div>
        <div class="psi-rings">
          <div class="psi-ring">@include('partials.score', ['value' => $m?->performance, 'class' => 'big', 'title' => 'Prestaties mobiel'])<span>Mobiel</span></div>
          <div class="psi-ring">@include('partials.score', ['value' => $row['desktop']?->performance, 'class' => 'big', 'title' => 'Prestaties desktop'])<span>Desktop</span></div>
          <div class="psi-small">
            <div><span>Toegankelijkheid</span>@include('partials.score', ['value' => $m?->accessibility])</div>
            <div><span>Best practices</span>@include('partials.score', ['value' => $m?->best_practices])</div>
            <div><span>SEO</span>@include('partials.score', ['value' => $m?->seo])</div>
          </div>
        </div>
        <div class="site-stats">
          <div><span>LCP</span><b>{{ $m?->lcp_ms !== null ? number_format($m->lcp_ms / 1000, 1, ',', '') . ' s' : '–' }}</b></div>
          <div><span>CLS</span><b>{{ $m?->cls !== null ? number_format($m->cls, 2, ',', '') : '–' }}</b></div>
          <div><span>INP (bezoekers)</span><b>{{ isset($m?->field['inp_ms']) ? $m->field['inp_ms'] . ' ms' : '–' }}</b></div>
        </div>
        <div class="site-card-foot">
          <span class="psi-trend" title="Trend mobiel">@include('partials.spark', ['values' => $row['history']])</span>
          @can('manage-sites')
            <span class="open">
              @if ($row['site']->pagespeed_requested_at)
                @include('partials.row-action', ['action' => ['kind' => 'done', 'label' => 'Aangevraagd']])
              @else
                @include('partials.row-action', ['action' => ['kind' => 'form', 'label' => 'Meten', 'action' => route('sites.pagespeed', $row['site']), 'fields' => [], 'confirm' => null]])
              @endif
            </span>
          @endcan
        </div>
      </div>
    @empty
      <div class="empty">Nog geen gekoppelde sites.</div>
    @endforelse
  </div>
  <p class="note mt">Scores en LCP/CLS zijn labmetingen van Lighthouse (mobiel). INP komt uit de velddata van echte Chrome-bezoekers (CrUX) en is er alleen bij sites met genoeg verkeer. Groen ≥ 90, oranje ≥ 50, rood &lt; 50.</p>
@endsection
