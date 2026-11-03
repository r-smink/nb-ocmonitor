@php($fmt = fn ($date) => $date->locale('nl')->translatedFormat('j F Y'))
<div class="cover-text" style="padding: 32mm {{ $design['margin_side'] }}mm 0;">
  @if ($logo)<img src="{{ $logo }}" style="max-height: 22mm; max-width: 70mm;">@endif
  <div style="height: 70mm;"></div>
  <p class="primary" style="font-size: 11pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase;">{{ $design['title'] }}</p>
  <h1 class="cover-text" style="color: {{ $design['cover_text_color'] }};">{{ $d['customer']['name'] }}</h1>
  <p style="font-size: 12pt; margin-top: 6pt;">{{ $fmt($d['from']) }} – {{ $fmt($d['to']) }}</p>
  <div style="height: 14mm;"></div>
  <table style="font-size: 9.5pt;">
    @if ($d['customer']['contact'])<tr><td class="muted" style="padding-right: 10pt;">Voor</td><td>{{ $d['customer']['contact'] }}</td></tr>@endif
    <tr><td class="muted" style="padding-right: 10pt;">Onderhoud</td><td>{{ $d['customer']['contract'] }}</td></tr>
    <tr><td class="muted" style="padding-right: 10pt;">Websites</td><td>{{ implode(', ', array_column($d['sites'], 'name')) ?: '–' }}</td></tr>
    <tr><td class="muted" style="padding-right: 10pt;">Opgesteld door</td><td>{{ $design['company'] }}</td></tr>
  </table>
</div>
