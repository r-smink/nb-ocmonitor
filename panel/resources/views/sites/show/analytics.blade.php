{{-- Bezoekers (GA4) en Google-zoekresultaten (Search Console), laatste 28 dagen. --}}
@php $googleOn = \App\Ocm\GoogleApi::connected(); @endphp
@php $ga = null; @endphp
@php $googleError = null; @endphp
@if ($googleOn)
  @php
    try {
        $ga = \App\Ocm\GoogleApi::report($site, \Carbon\CarbonImmutable::today()->subDays(28), \Carbon\CarbonImmutable::today()->subDay());
        $gaProps = \App\Ocm\GoogleApi::ga4Properties();
        $gscProps = \App\Ocm\GoogleApi::gscSites();
    } catch (\Throwable $e) {
        $googleError = $e->getMessage();
        $gaProps = [];
        $gscProps = [];
    }
  @endphp
@endif
<section class="sd-section" id="sd-bezoekers" data-spy="bezoekers">
  <div class="panel">
    <div class="panel-head">
      <span class="sd-ic">@include('partials.icon', ['name' => 'activity', 'size' => 18])</span>
      <h3>Bezoekers en Google</h3>
      <span class="hint">laatste 28 dagen</span>
    </div>
    @if (!$googleOn)
      <p class="muted">Google is nog niet gekoppeld. Koppel het onder Instellingen → Algemeen en menu → Google.</p>
    @else
      @if ($googleError)<div class="alert warn">{{ $googleError }}</div>@endif
      @foreach ((array) ($ga['errors'] ?? []) as $err)<div class="alert warn">{{ $err }}</div>@endforeach
      @can('manage-sites')
        <form method="POST" action="{{ route('sites.analytics', $site) }}" class="form-row">
          @csrf
          <label>Google Analytics 4
            <select name="ga4_property">
              <option value="">Niet gekoppeld</option>
              @foreach ($gaProps as $id => $name)<option value="{{ $id }}" @selected($site->ga4_property === $id)>{{ $name }}</option>@endforeach
            </select>
          </label>
          @php $suggest = $site->gsc_property ?? \App\Ocm\GoogleApi::suggestGsc($site, $gscProps); @endphp
          <label>Search Console
            <select name="gsc_property">
              <option value="">Niet gekoppeld</option>
              @foreach ($gscProps as $prop)<option value="{{ $prop }}" @selected($suggest === $prop)>{{ $prop }}</option>@endforeach
            </select>
          </label>
          <div class="self-end"><button class="btn ghost small" type="submit">Koppelen</button></div>
        </form>
      @endcan

      @if (!empty($ga['ga4']))
        @php $g = $ga['ga4']; @endphp
        <div class="psi-cats mt">
          <div class="psi-cat"><b class="kpi-num">{{ number_format($g['users'], 0, ',', '.') }}</b>bezoekers</div>
          <div class="psi-cat"><b class="kpi-num">{{ number_format($g['sessions'], 0, ',', '.') }}</b>sessies</div>
          <div class="psi-cat"><b class="kpi-num">{{ number_format($g['pageviews'], 0, ',', '.') }}</b>paginaweergaven</div>
          <div class="psi-cat"><b class="kpi-num">{{ number_format($g['engagement'], 1, ',', '') }}%</b>betrokken</div>
        </div>
        @if (count($g['daily']) >= 2)@include('partials.spark', ['values' => $g['daily'], 'class' => 'wide'])@endif
        <div class="cols">
          <div>
            <h4>Populairste pagina's</h4>
            <table><tbody>@foreach ($g['pages'] as $p)<tr><td class="wrap mono">{{ \Illuminate\Support\Str::limit($p['path'], 60) }}</td><td class="num">{{ number_format($p['views'], 0, ',', '.') }}</td></tr>@endforeach</tbody></table>
          </div>
          <div>
            <h4>Herkomst</h4>
            <table><tbody>@foreach ($g['sources'] as $s)<tr><td>{{ $s['name'] }}</td><td class="num">{{ number_format($s['sessions'], 0, ',', '.') }}</td></tr>@endforeach</tbody></table>
          </div>
        </div>
      @endif
      @if (!empty($ga['gsc']))
        @php $s = $ga['gsc']; @endphp
        <h4 class="mt">Google-zoekresultaten</h4>
        <div class="psi-cats">
          <div class="psi-cat"><b class="kpi-num">{{ number_format($s['clicks'], 0, ',', '.') }}</b>klikken</div>
          <div class="psi-cat"><b class="kpi-num">{{ number_format($s['impressions'], 0, ',', '.') }}</b>vertoningen</div>
          <div class="psi-cat"><b class="kpi-num">{{ number_format($s['ctr'], 1, ',', '') }}%</b>CTR</div>
          <div class="psi-cat"><b class="kpi-num">{{ number_format($s['position'], 1, ',', '') }}</b>gem. positie</div>
        </div>
        @if ($s['queries'] !== [])
          <table class="mt"><thead><tr><th>Zoekterm</th><th class="num">Klikken</th><th class="num">Vertoningen</th><th class="num">Positie</th></tr></thead>
            <tbody>@foreach ($s['queries'] as $q)<tr><td class="wrap">{{ $q['query'] }}</td><td class="num">{{ $q['clicks'] }}</td><td class="num">{{ number_format($q['impressions'], 0, ',', '.') }}</td><td class="num">{{ number_format($q['position'], 1, ',', '') }}</td></tr>@endforeach</tbody>
          </table>
        @endif
      @endif
      @if ($ga === null && !$googleError)<p class="muted mt">Kies hierboven de Analytics- en Search Console-property van deze site.</p>@endif
    @endif
  </div>
</section>
