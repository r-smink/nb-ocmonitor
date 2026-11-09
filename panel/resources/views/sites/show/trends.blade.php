{{-- Trends: database, bestanden, plugins en PageSpeed over de afgelopen maanden, als kleine grafieken naast elkaar. --}}
@php($bytes = fn ($v) => \App\Ocm\SiteMetrics::bytes($v))
<section class="sd-section" id="sd-trends" data-spy="trends">
  <div class="panel">
    <div class="panel-head">
      <span class="sd-ic">@include('partials.icon', ['name' => 'activity', 'size' => 18])</span>
      <h3>Trends</h3>
      <span class="hint">laatste {{ \App\Ocm\SiteMetrics::DAYS }} dagen</span>
    </div>
    <div class="trend-grid">
      @include('partials.trend-chart', ['title' => 'Database', 'points' => $trends['db'], 'format' => $bytes])
      @include('partials.trend-chart', ['title' => 'Bestanden (wp-content)', 'points' => $trends['content'], 'format' => $bytes])
      @include('partials.trend-chart', ['title' => 'Plugins', 'points' => $trends['plugins'], 'integer' => true])
      @if ($showPsi)
        @include('partials.trend-chart', ['title' => 'PageSpeed mobiel', 'points' => $trends['psi_mobile'], 'range' => [0, 100]])
        @include('partials.trend-chart', ['title' => 'PageSpeed desktop', 'points' => $trends['psi_desktop'], 'range' => [0, 100]])
      @endif
    </div>
    <p class="note mt">Database, bestanden en plugins komen uit de dagelijkse inventaris (bestanden vanaf client-plugin 0.13.0, hooguit één meting per dag). Op heel grote sites stopt het tellen na 8 seconden; dan is de waarde een ondergrens.</p>
  </div>
</section>
