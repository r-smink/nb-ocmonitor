{{--
  Lijngrafiek zonder JavaScript: één reeks, één as. Hover via <title> op brede trefvlakken per punt,
  een tabel als alternatief. $points = [[CarbonImmutable, getal], ...] oudste eerst,
  $format = fn (getal) => tekst, $range = [min, max] voor een vaste schaal (bijv. 0-100), $title.
--}}
@php
  $tcPoints = array_values($points);
  $tcCount = count($tcPoints);
  $tcFormat = $format ?? fn ($v) => (string) $v;
  $tcLast = $tcCount > 0 ? $tcPoints[$tcCount - 1][1] : null;
  $tcDiff = $tcCount >= 2 ? $tcLast - $tcPoints[0][1] : 0;
@endphp
<figure class="trend">
  <figcaption class="trend-head">
    <span class="trend-title">{{ $title }}</span>
    @if ($tcCount > 0)
      <b class="trend-value">{{ $tcFormat($tcLast) }}</b>
      @if ($tcCount >= 2)
        <span class="trend-delta">{{ $tcDiff == 0 ? 'gelijk' : ($tcDiff > 0 ? '+' : '−') . $tcFormat(abs($tcDiff)) }} sinds {{ $tcPoints[0][0]->timezone('Europe/Amsterdam')->format('d-m') }}</span>
      @endif
    @endif
  </figcaption>
  @if ($tcCount < 2)
    <p class="muted trend-empty">{{ $tcCount === 0 ? 'Nog geen metingen.' : 'Vanaf de tweede meting verschijnt hier een lijn.' }}</p>
  @else
    @php
      $tcValues = array_column($tcPoints, 1);
      [$tcMin, $tcMax] = $range ?? [min($tcValues), max($tcValues)];
      if (!isset($range)) {
          $tcPad = max(($tcMax - $tcMin) * 0.15, $integer ?? false ? 1 : max(abs($tcMax) * 0.02, 1));
          $tcMin = max(0, $tcMin - $tcPad);
          $tcMax += $tcPad;
      }
      $tcSpan = max($tcMax - $tcMin, 1e-9);
      $tcStart = $tcPoints[0][0]->getTimestamp();
      $tcWidth = max($tcPoints[$tcCount - 1][0]->getTimestamp() - $tcStart, 1);
      $tcX = fn ($p) => round(($p[0]->getTimestamp() - $tcStart) / $tcWidth * 300, 2);
      $tcY = fn ($p) => round(96 - ($p[1] - $tcMin) / $tcSpan * 92, 2);
      $tcLine = 'M' . implode(' L', array_map(fn ($p) => $tcX($p) . ',' . $tcY($p), $tcPoints));
      $tcLastPoint = $tcPoints[$tcCount - 1];
    @endphp
    <div class="trend-plot">
      <span class="trend-y top">{{ $tcFormat($integer ?? false ? (int) round($tcMax) : $tcMax) }}</span>
      <span class="trend-y bottom">{{ $tcFormat($integer ?? false ? (int) round($tcMin) : $tcMin) }}</span>
      <svg viewBox="0 0 300 100" preserveAspectRatio="none" role="img" aria-label="{{ $title }}: van {{ $tcFormat($tcPoints[0][1]) }} naar {{ $tcFormat($tcLastPoint[1]) }}">
        <line class="grid" x1="0" y1="4" x2="300" y2="4" vector-effect="non-scaling-stroke"/>
        <line class="grid" x1="0" y1="50" x2="300" y2="50" vector-effect="non-scaling-stroke"/>
        <line class="base" x1="0" y1="96" x2="300" y2="96" vector-effect="non-scaling-stroke"/>
        <path class="area" d="{{ $tcLine }} L300,96 L0,96 Z"/>
        <path class="line" d="{{ $tcLine }}" vector-effect="non-scaling-stroke"/>
        @foreach ($tcPoints as $i => $p)
          @php
            $tcLeft = $i === 0 ? 0 : ($tcX($tcPoints[$i - 1]) + $tcX($p)) / 2;
            $tcRight = $i === $tcCount - 1 ? 300 : ($tcX($p) + $tcX($tcPoints[$i + 1])) / 2;
          @endphp
          <rect class="hit" x="{{ $tcLeft }}" y="0" width="{{ max($tcRight - $tcLeft, 0.5) }}" height="100"><title>{{ $p[0]->timezone('Europe/Amsterdam')->format('d-m-Y') }}: {{ $tcFormat($p[1]) }}</title></rect>
        @endforeach
        {{-- Laatste punt: een lijn van lengte 0 met ronde uiteinden blijft rond, ook als de grafiek uitrekt. --}}
        <path class="dot" d="M{{ $tcX($tcLastPoint) }},{{ $tcY($tcLastPoint) }} L{{ $tcX($tcLastPoint) }},{{ $tcY($tcLastPoint) }}" vector-effect="non-scaling-stroke"/>
      </svg>
    </div>
    <div class="trend-x"><span>{{ $tcPoints[0][0]->timezone('Europe/Amsterdam')->format('d-m-Y') }}</span><span>{{ $tcLastPoint[0]->timezone('Europe/Amsterdam')->format('d-m-Y') }}</span></div>
    <details class="trend-table">
      <summary>Tabel</summary>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Datum</th><th>{{ $title }}</th></tr></thead>
          <tbody>
            @foreach (array_reverse($tcPoints) as $p)
              <tr><td>{{ $p[0]->timezone('Europe/Amsterdam')->format('d-m-Y') }}</td><td>{{ $tcFormat($p[1]) }}</td></tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </details>
  @endif
</figure>
