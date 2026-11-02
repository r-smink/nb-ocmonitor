{{-- Kleine trendlijn (0-100) zonder JavaScript; zelfde opmaak als de sparklines in ServerDash. --}}
@php
  $sparkValues = array_values(array_map('intval', $values));
  $sparkCount = count($sparkValues);
@endphp
@if ($sparkCount >= 2)
  @php
    $sparkPoints = [];
    foreach ($sparkValues as $i => $v) {
        $sparkPoints[] = round($i * 120 / ($sparkCount - 1), 1) . ',' . round(28 - max(0, min(100, $v)) * 0.26, 1);
    }
    $sparkLine = 'M' . implode(' L', $sparkPoints);
    $sparkLevel = \App\Models\PagespeedResult::level(end($sparkValues));
  @endphp
  <svg class="spark {{ $sparkLevel }} {{ $class ?? '' }}" viewBox="0 0 120 30" preserveAspectRatio="none" aria-label="Trend: {{ implode(', ', $sparkValues) }}" role="img"><path class="a" d="{{ $sparkLine }} L120,30 L0,30 Z"/><path class="l" d="{{ $sparkLine }}" vector-effect="non-scaling-stroke"/></svg>
@else
  <span class="muted">–</span>
@endif
