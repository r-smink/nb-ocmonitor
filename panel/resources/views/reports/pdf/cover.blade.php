{{-- Voorpagina (mPDF: geen flex/grid, alleen tabellen en blokken). De rode balk bovenaan, de pijl en de
     contactregel onderaan staan in de achtergrond (background_cover). Maten volgen export/cover_1h_mockup.png. --}}
@php($dark = $coverDark ?? false)
@php($line = $dark ? '#3A3A3A' : '#D1D5DB')
@php($soft = $dark ? '#9B9B98' : $design['color_muted'])
@php($boxBg = $dark ? '#262626' : '#F7F6F3')
@php($bar = $dark ? '#232323' : '#EFEEEA')
@php($pill = $dark ? '#161616' : '#FFFFFF')
@php($pillText = $dark ? '#C9C9C6' : $design['color_muted'])
{{-- Periode: "1 aug - 31 okt" met het jaar eronder in rood; over de jaargrens heen beide datums met jaar. --}}
@php($sameYear = $d['from']->year === $d['to']->year)
@php($periodTop = $sameYear ? $d['from']->locale('nl')->translatedFormat('j M') . ' - ' . $d['to']->locale('nl')->translatedFormat('j M') : $d['from']->locale('nl')->translatedFormat('j M Y') . ' -')
@php($periodBottom = $sameYear ? $d['to']->format('Y') : $d['to']->locale('nl')->translatedFormat('j M Y'))
@php($host = preg_replace('#^(https?://)?(www\.)?#i', '', rtrim((string) ($coverShotUrl ?? ''), '/')))
<div style="padding: 32mm {{ $design['margin_side'] }}mm 0; color: {{ $design['cover_text_color'] }};">
  @if ($logo)<img src="{{ $logo }}" style="max-height: 15mm; max-width: 50mm;">@endif
  <div style="height: 10mm;"></div>

  {{-- Titel links, periode-kader rechts, verticaal gecentreerd ten opzichte van elkaar --}}
  <table width="100%" style="border-collapse: collapse;"><tr>
    <td style="vertical-align: middle; width: 56%; padding: 0;">
      <p style="font-family: {{ $design['font_heading'] }}; font-size: 10pt; font-weight: bold; letter-spacing: 2.4pt; text-transform: uppercase; color: {{ $design['color_primary'] }}; margin: 0;">{{ $design['title'] }}</p>
      <h1 style="color: {{ $design['cover_text_color'] }}; font-size: 30pt; line-height: 1.1; margin: 3pt 0 0;">{{ $d['customer']['name'] }}</h1>
      <p style="font-size: 11pt; margin: 8pt 0 0; color: {{ $soft }};">{{ $d['customer']['contract'] }}@if ($d['sites']) · {{ count($d['sites']) }} {{ count($d['sites']) === 1 ? 'website' : 'websites' }}@endif</p>
    </td>
    <td style="vertical-align: middle; width: 44%; padding: 0;">
      <div style="background: {{ $boxBg }}; border: 0.75pt solid {{ $line }}; border-radius: 10pt; padding: 6mm 6.3mm 6.5mm;">
        <div style="width: 9mm; height: 0.9mm; background: {{ $design['color_primary'] }};"></div>
        <p style="font-family: {{ $design['font_heading'] }}; font-size: 8.5pt; font-weight: bold; letter-spacing: 2pt; text-transform: uppercase; color: {{ $soft }}; margin: 5mm 0 3mm;">Rapportperiode</p>
        <p style="font-family: {{ $design['font_heading'] }}; font-size: 18pt; font-weight: bold; line-height: 1.12; margin: 0; color: {{ $design['cover_text_color'] }};">{{ $periodTop }}</p>
        <p style="font-family: {{ $design['font_heading'] }}; font-size: 18pt; font-weight: bold; line-height: 1.12; margin: 0; color: {{ $design['color_primary'] }};">{{ $periodBottom }}</p>
      </div>
    </td>
  </tr></table>

  {{-- Screenshot van de homepage na de laatste updates (visuele controle), in een browserkader --}}
  @if (!empty($coverShot))
    <div style="height: 9mm;"></div>
    <div style="border: 1pt solid {{ $line }}; border-radius: 10pt; background: {{ $bar }};">
      <table width="100%" style="border-collapse: collapse;"><tr>
        <td style="width: 22mm; padding: 3mm 0 3mm 4.5mm; vertical-align: middle; font-size: 12pt; line-height: 1;"><span style="color: #E5484D;">●</span> <span style="color: #E8A93A;">●</span> <span style="color: #4CBB5F;">●</span></td>
        <td style="padding: 2.2mm 0; vertical-align: middle;">
          <div style="width: {{ min(110, 12 + (int) round(mb_strlen($host) * 1.9)) }}mm; background: {{ $pill }}; border-radius: 5pt; padding: 1.6mm 4mm; font-size: 9pt; color: {{ $pillText }};">{{ $host }}</div>
        </td>
      </tr></table>
      <img src="{{ $coverShot }}" style="width: 100%; display: block;">
    </div>
  @endif
</div>
