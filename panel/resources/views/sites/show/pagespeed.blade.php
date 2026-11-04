{{-- Sectie 5: PageSpeed, ronde scores mobiel/desktop met de kerncijfers; details ingeklapt. --}}
@php($mob = $pagespeed->get('mobile'))
@php($desk = $pagespeed->get('desktop'))
<section class="sd-section" id="sd-pagespeed" data-spy="pagespeed">
  <div class="panel">
    <div class="panel-head">
      <span class="sd-ic">@include('partials.icon', ['name' => 'gauge', 'size' => 18])</span>
      <h3>PageSpeed</h3>
      @if ($pagespeed->isNotEmpty())<span class="hint">gemeten {{ $pagespeed->first()->measured_at->diffForHumans() }}</span>@endif
      @can('manage-sites')
        <div class="right">
          @if ($site->pagespeed_requested_at)
            @include('partials.row-action', ['action' => ['kind' => 'done', 'label' => 'Aangevraagd']])
          @else
            <form method="POST" action="{{ route('sites.pagespeed', $site) }}" class="inline">
              @csrf
              <button class="btn ghost small" type="submit">Opnieuw meten</button>
            </form>
          @endif
        </div>
      @endcan
    </div>
    @if ($pagespeedLastError)
      <p class="note">Laatste meting mislukt: {{ $pagespeedLastError->error }}</p>
    @endif
    @if ($pagespeed->isEmpty())
      <p class="muted">Nog niet gemeten. De eerste meting volgt automatisch, of kies "Opnieuw meten".</p>
    @else
      <div class="sd-psi">
        <div class="psi-ring">@include('partials.score', ['value' => $mob?->performance, 'class' => 'big', 'title' => 'Prestaties mobiel'])<span>mobiel</span></div>
        <div class="psi-ring">@include('partials.score', ['value' => $desk?->performance, 'class' => 'big', 'title' => 'Prestaties desktop'])<span>desktop</span></div>
        <div class="sd-psi-metrics">
          <div><span>LCP</span><b>{{ $mob?->lcp_ms !== null ? number_format($mob->lcp_ms / 1000, 1, ',', '') . ' s' : '–' }}</b></div>
          <div><span>CLS</span><b>{{ $mob?->cls !== null ? number_format($mob->cls, 3, ',', '') : '–' }}</b></div>
          <div><span>TBT</span><b>{{ $mob?->tbt_ms !== null ? $mob->tbt_ms . ' ms' : '–' }}</b></div>
        </div>
        @if (count($pagespeedHistory) >= 2)
          <div class="sd-psi-trend" title="Trend prestaties mobiel">@include('partials.spark', ['values' => $pagespeedHistory, 'class' => 'wide'])</div>
        @endif
      </div>
      <details class="mt">
        <summary>Alle cijfers en aanbevelingen</summary>
        <div class="psi-grid mt">
          @foreach (\App\Models\PagespeedResult::STRATEGIES as $strategy => $label)
            @php($r = $pagespeed->get($strategy))
            <div>
              <div class="psi-head">{{ $label }}</div>
              @if ($r)
                <div class="psi-cats">
                  @foreach (['performance' => 'Prestaties', 'accessibility' => 'Toegankelijkheid', 'best_practices' => 'Best practices', 'seo' => 'SEO'] as $field => $name)
                    <div class="psi-cat">@include('partials.score', ['value' => $r->{$field}, 'class' => 'big']){{ $name }}</div>
                  @endforeach
                </div>
                <dl class="kv">
                  <dt>LCP</dt><dd>{{ $r->lcp_ms !== null ? number_format($r->lcp_ms / 1000, 1, ',', '') . ' s' : '–' }}@if (isset($r->field['lcp_ms'])) <span class="muted">· bezoekers {{ number_format($r->field['lcp_ms'] / 1000, 1, ',', '') }} s</span>@endif</dd>
                  <dt>CLS</dt><dd>{{ $r->cls !== null ? number_format($r->cls, 3, ',', '') : '–' }}@if (isset($r->field['cls'])) <span class="muted">· bezoekers {{ number_format($r->field['cls'], 2, ',', '') }}</span>@endif</dd>
                  <dt>INP (bezoekers)</dt><dd>{{ isset($r->field['inp_ms']) ? $r->field['inp_ms'] . ' ms' : 'geen velddata' }}</dd>
                  <dt>FCP / TBT</dt><dd>{{ $r->fcp_ms !== null ? number_format($r->fcp_ms / 1000, 1, ',', '') . ' s' : '–' }} / {{ $r->tbt_ms !== null ? $r->tbt_ms . ' ms' : '–' }}</dd>
                  <dt>Serverreactie</dt><dd>{{ $r->ttfb_ms !== null ? $r->ttfb_ms . ' ms' : '–' }}</dd>
                </dl>
              @else
                <p class="muted">Geen geslaagde meting.</p>
              @endif
            </div>
          @endforeach
        </div>
        @php($opportunities = $mob?->opportunities ?? [])
        @if ($opportunities !== [])
          <h4 class="mt">Grootste besparingen (mobiel)</h4>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Aanbeveling</th><th class="num">Besparing</th><th>Details</th></tr></thead>
              <tbody>
                @foreach ($opportunities as $o)
                  <tr><td class="wrap">{{ $o['title'] }}</td><td class="num">{{ number_format($o['savings_ms'] / 1000, 1, ',', '') }} s</td><td class="muted">{{ $o['display'] ?? '' }}</td></tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </details>
    @endif
  </div>
</section>
