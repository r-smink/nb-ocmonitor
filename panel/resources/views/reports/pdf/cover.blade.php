{{-- Voorpagina (mPDF: geen flex/grid, alleen tabellen en blokken). Contactregel en pijl staan in de achtergrond (background_cover). --}}
@php($fmt = fn ($date) => $date->locale('nl')->translatedFormat('j M Y'))
{{-- Kaders en grijstinten voor de donkere voorpagina; zonder eigen achtergrond lichte varianten. --}}
@php($line = ($coverDark ?? false) ? '#3C3C3C' : '#D1D5DB')
@php($soft = ($coverDark ?? false) ? '#9B9B98' : $design['color_muted'])
@php($bar = ($coverDark ?? false) ? '#242424' : '#F3F4F6')
<div style="padding: 28mm {{ $design['margin_side'] }}mm 0; color: {{ $design['cover_text_color'] }};">
  @if ($logo)<img src="{{ $logo }}" style="max-height: 18mm; max-width: 60mm;">@endif
  <div style="height: 16mm;"></div>

  {{-- Titel links, periode-kader rechts --}}
  <table width="100%" style="border-collapse: collapse;"><tr>
    <td style="vertical-align: top; width: 62%;">
      <p class="primary" style="font-size: 10pt; font-weight: bold; letter-spacing: 1.4pt; text-transform: uppercase; color: {{ $design['color_primary'] }};">{{ $design['title'] }}</p>
      <h1 style="color: {{ $design['cover_text_color'] }}; font-size: 26pt; line-height: 1.1; margin: 2pt 0 0;">{{ $d['customer']['name'] }}</h1>
      <p style="font-size: 10.5pt; margin-top: 7pt; color: {{ $soft }};">{{ $d['customer']['contract'] }}@if ($d['sites']) · {{ count($d['sites']) }} {{ count($d['sites']) === 1 ? 'website' : 'websites' }}@endif</p>
    </td>
    <td style="vertical-align: top; width: 38%; padding-left: 10mm;">
      <div style="border: 0.75pt solid {{ $line }}; border-radius: 10pt; padding: 11pt 13pt;">
        <div style="width: 26pt; height: 2.5pt; background: {{ $design['color_primary'] }};"></div>
        <p style="font-size: 7.5pt; font-weight: bold; letter-spacing: 1.2pt; text-transform: uppercase; color: {{ $soft }}; margin: 7pt 0 5pt;">Rapportperiode</p>
        <p style="font-size: 15pt; font-weight: bold; line-height: 1.15; margin: 0; color: {{ $design['cover_text_color'] }};">{{ $fmt($d['from']) }} –</p>
        <p style="font-size: 15pt; font-weight: bold; line-height: 1.15; margin: 0; color: {{ $design['color_primary'] }};">{{ $fmt($d['to']) }}</p>
      </div>
    </td>
  </tr></table>

  {{-- Screenshot van de homepage na de laatste updates (visuele controle) --}}
  @if (!empty($coverShot))
    <div style="height: 10mm;"></div>
    <div style="border: 0.75pt solid {{ $line }}; border-radius: 9pt; overflow: hidden;">
      <div style="background: {{ $bar }}; padding: 5pt 9pt;">
        <span style="color: {{ $soft }}; font-size: 7.5pt;">● ● ●&nbsp;&nbsp;&nbsp;{{ $coverShotUrl }}</span>
      </div>
      <img src="{{ $coverShot }}" style="width: 100%; display: block;">
    </div>
    <p style="font-size: 7.5pt; color: {{ $soft }}; margin-top: 5pt;">Visuele controle van de homepage na de laatste update.</p>
  @endif
</div>
