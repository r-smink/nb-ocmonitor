{{-- Kapotte links en afbeeldingen op deze site. --}}
@php($siteLinks = \App\Models\BrokenLink::query()->where('site_id', $site->id)->whereNull('ignored_at')->orderByDesc('internal')->orderBy('url')->limit(200)->get()->each->setRelation('site', $site))
@php($lastScan = \App\Models\LinkScan::query()->where('site_id', $site->id)->latest('id')->first())
<section class="sd-section" id="sd-links" data-spy="links">
  <div class="panel">
    <div class="panel-head">
      <span class="sd-ic">@include('partials.icon', ['name' => 'filetext', 'size' => 18])</span>
      <h3>Kapotte links</h3>
      @if ($siteLinks->isNotEmpty())<span class="count-pill crit">{{ $siteLinks->count() }}</span>@endif
      <span class="hint">
        @if ($lastScan?->status === 'running') controle loopt…
        @elseif ($lastScan) gecontroleerd {{ $lastScan->finished_at?->diffForHumans() }}: {{ $lastScan->pages }} pagina's, {{ $lastScan->links }} links{{ $lastScan->status === 'failed' ? ' (mislukt: ' . $lastScan->error . ')' : '' }}
        @else nog niet gecontroleerd @endif
      </span>
      @can('manage-sites')
        <div class="right">
          @if ($site->links_requested_at)<span class="state idle">aangevraagd</span>@else
            <form method="POST" action="{{ route('sites.links.request', $site) }}" class="inline">@csrf<button class="btn ghost small" type="submit">Nu controleren</button></form>
          @endif
        </div>
      @endcan
    </div>
    @if ($siteLinks->isEmpty() && $lastScan?->status === 'done')
      <div class="sd-ok">@include('partials.icon', ['name' => 'check', 'size' => 18]) <span>Geen kapotte links gevonden.</span></div>
    @elseif ($siteLinks->isNotEmpty())
      @include('links.table', ['links' => $siteLinks, 'showSite' => false])
    @endif
  </div>
</section>
