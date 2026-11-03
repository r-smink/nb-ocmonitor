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

  <section class="panel">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Site</th><th>Mobiel</th><th>Desktop</th><th>Toegankelijkheid</th><th>Best practices</th><th>SEO</th>
            <th class="num">LCP</th><th class="num">CLS</th><th class="num">INP (bezoekers)</th><th>Trend mobiel</th><th>Gemeten</th>@can('manage-sites')<th></th>@endcan
          </tr>
        </thead>
        <tbody>
          @forelse ($rows as $row)
            @php($m = $row['mobile'])
            <tr data-href="{{ route('sites.show', $row['site']) }}">
              <td><a href="{{ route('sites.show', $row['site']) }}"><b>{{ $row['site']->name }}</b></a></td>
              <td>@include('partials.score', ['value' => $m?->performance])</td>
              <td>@include('partials.score', ['value' => $row['desktop']?->performance])</td>
              <td>@include('partials.score', ['value' => $m?->accessibility])</td>
              <td>@include('partials.score', ['value' => $m?->best_practices])</td>
              <td>@include('partials.score', ['value' => $m?->seo])</td>
              <td class="num">{{ $m?->lcp_ms !== null ? number_format($m->lcp_ms / 1000, 1, ',', '') . ' s' : '–' }}</td>
              <td class="num">{{ $m?->cls !== null ? number_format($m->cls, 2, ',', '') : '–' }}</td>
              <td class="num">{{ isset($m?->field['inp_ms']) ? $m->field['inp_ms'] . ' ms' : '–' }}</td>
              <td>@include('partials.spark', ['values' => $row['history']])</td>
              <td class="muted">
                {{ $m?->measured_at?->diffForHumans() ?? 'nog niet' }}
                @if ($row['site']->pagespeed_requested_at)<div><span class="state idle">aangevraagd</span></div>@endif
              </td>
              @can('manage-sites')
                <td>
                  <form method="POST" action="{{ route('sites.pagespeed', $row['site']) }}" class="inline">
                    @csrf
                    <button class="btn ghost small" type="submit" @disabled($row['site']->pagespeed_requested_at)>Meten</button>
                  </form>
                </td>
              @endcan
            </tr>
          @empty
            <tr><td colspan="12" class="muted">Nog geen gekoppelde sites.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <p class="note mt">Scores en LCP/CLS zijn labmetingen van Lighthouse (mobiel). INP komt uit de velddata van echte Chrome-bezoekers (CrUX) en is er alleen bij sites met genoeg verkeer. Groen ≥ 90, oranje ≥ 50.</p>
  </section>
@endsection
