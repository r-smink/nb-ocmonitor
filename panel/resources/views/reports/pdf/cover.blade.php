{{-- Voorpagina (mPDF: geen flex/grid). De rode balk bovenaan, de pijl en de contactregel onderaan staan in de
     achtergrond (background_cover). Maten volgen export/cover_1h_mockup.png.
     Let op: mPDF tekent achtergrond en rand van een blok binnen een tabelcel niet; daarom zwevende blokken. --}}
@php($dark = $coverDark ?? false)
@php($line = $dark ? '#3A3A3A' : '#D1D5DB')
@php($soft = $dark ? '#9B9B98' : $design['color_muted'])
@php($boxBg = $dark ? '#262626' : '#F7F6F3')
@php($bar = $dark ? '#232323' : '#EFEEEA')
@php($pill = $dark ? '#161616' : '#FFFFFF')
@php($pillText = $dark ? '#C9C9C6' : $design['color_muted'])
{{-- Periode: "1 aug - 31 okt" met het jaar eronder in rood; over de jaargrens heen beide datums met jaar. --}}
@php($day = fn ($date, string $format) => str_replace('.', '', $date->locale('nl')->translatedFormat($format)))
@php($sameYear = $d['from']->year === $d['to']->year)
@php($periodTop = $sameYear ? $day($d['from'], 'j M') . ' - ' . $day($d['to'], 'j M') : $day($d['from'], 'j M Y') . ' -')
@php($periodBottom = $sameYear ? $d['to']->format('Y') : $day($d['to'], 'j M Y'))
@php($host = preg_replace('#^(https?://)?(www\.)?#i', '', rtrim((string) ($coverShotUrl ?? ''), '/')))
@php($name = $d['customer']['name'])
{{-- Titelgrootte meet ReportPdf met het echte font, zodat de klantnaam naast het periodekader past. --}}
@php($titleSize = $titleSize ?? 26)
<div style="padding: 32mm {{ $design['margin_side'] }}mm 0; color: {{ $design['cover_text_color'] }};">
  {{-- Ook zonder logo dezelfde ruimte, zodat de opmaak niet verschuift. --}}
  <div style="height: 15mm;">@if ($logo)<img src="{{ $logo }}" style="max-height: 15mm; max-width: 50mm;">@endif</div>
  <div style="height: 10mm;"></div>

  {{-- Periode-kader rechts (zwevend), titel links --}}
  <div style="float: right; width: 36%; background-color: {{ $boxBg }}; border: 0.75pt solid {{ $line }}; border-radius: 10pt; padding: 6mm 6.3mm 6.5mm;">
    <div style="width: 9mm; height: 0.9mm; background-color: {{ $design['color_primary'] }}; font-size: 1pt;">&nbsp;</div>
    <div style="font-family: {{ $design['font_heading'] }}; font-size: 8pt; font-weight: bold; letter-spacing: 2pt; text-transform: uppercase; color: {{ $soft }}; margin: 5mm 0 2.5mm;">Rapportperiode</div>
    <div style="font-family: {{ $design['font_heading'] }}; font-size: 18pt; font-weight: bold; line-height: 1.15; color: {{ $design['cover_text_color'] }};">{{ $periodTop }}</div>
    <div style="font-family: {{ $design['font_heading'] }}; font-size: 18pt; font-weight: bold; line-height: 1.15; color: {{ $design['color_primary'] }};">{{ $periodBottom }}</div>
  </div>
  <div style="float: left; width: 54%; padding-top: 7mm;">
    <div style="font-family: {{ $design['font_heading'] }}; font-size: 10pt; font-weight: bold; letter-spacing: 2.4pt; text-transform: uppercase; color: {{ $design['color_primary'] }};">{{ $design['title'] }}</div>
    <h1 style="color: {{ $design['cover_text_color'] }}; font-size: {{ $titleSize }}pt; line-height: 1.12; margin: 3pt 0 0;">{{ $name }}</h1>
    <div style="font-size: 11pt; margin-top: 8pt; color: {{ $soft }};">{{ $d['customer']['contract'] }}@if ($d['sites']) · {{ count($d['sites']) }} {{ count($d['sites']) === 1 ? 'website' : 'websites' }}@endif</div>
  </div>
  <div style="clear: both; height: 1pt; font-size: 1pt;">&nbsp;</div>

  {{-- Screenshot van de homepage na de laatste updates (visuele controle), in een browserkader --}}
  @if (!empty($coverShot))
    <div style="height: 9mm;"></div>
    <div style="border: 1pt solid {{ $line }}; border-radius: 10pt; background-color: {{ $bar }}; padding-bottom: 2.5mm;">
      <div style="padding: 2.6mm 4.5mm;">
        <span style="font-size: 12pt; color: #E5484D;">●</span> <span style="font-size: 12pt; color: #E8A93A;">●</span> <span style="font-size: 12pt; color: #4CBB5F;">●</span>
        &nbsp;&nbsp;&nbsp;<span style="background-color: {{ $pill }}; color: {{ $pillText }}; font-size: 9pt;">&nbsp;&nbsp;&nbsp;{{ $host }}&nbsp;&nbsp;&nbsp;</span>
      </div>
      <img src="{{ $coverShot }}" style="width: 100%; display: block;">
    </div>
  @endif
</div>
