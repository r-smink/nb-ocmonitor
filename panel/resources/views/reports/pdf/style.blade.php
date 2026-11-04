{{-- CSS voor mPDF (geen flex/grid; tabellen voor de opmaak). --}}
{{-- Benoemde pagina's: mPDF negeert een achtergrondafbeelding op een gewone @page als de pagina's met
     AddPage() worden gemaakt. ReportPdf opent de voorpagina met "cover" en de rest met "inhoud". --}}
@page cover {
  margin-top: 0; margin-bottom: 0; margin-left: 0; margin-right: 0; margin-header: 0; margin-footer: 0;
  @if ($coverImage) background: url("{{ $coverImage }}") no-repeat 0 0; background-image-resize: 6; @endif
}
@page inhoud {
  margin-top: {{ $design['margin_top'] }}mm; margin-bottom: {{ $design['margin_bottom'] }}mm;
  margin-left: {{ $design['margin_side'] }}mm; margin-right: {{ $design['margin_side'] }}mm; margin-header: 8mm; margin-footer: 8mm;
  @if ($pageImage) background: url("{{ $pageImage }}") no-repeat 0 0; background-image-resize: 6; @endif
}
body { font-family: {{ $design['font_body'] }}; color: {{ $design['color_text'] }}; font-size: {{ $design['font_size'] }}pt; line-height: 1.45; }
h1, h2, h3 { font-family: {{ $design['font_heading'] }}; color: {{ $design['color_accent'] }}; font-weight: bold; margin: 0; }
h1 { font-size: 26pt; line-height: 1.15; }
h2 { font-size: 15pt; margin: 18pt 0 8pt; padding-bottom: 4pt; border-bottom: 1.5pt solid {{ $design['color_primary'] }}; }
h3 { font-size: 11.5pt; margin: 12pt 0 5pt; }
p { margin: 0 0 6pt; }
.muted { color: {{ $design['color_muted'] }}; }
.small { font-size: {{ max(7, $design['font_size'] - 2) }}pt; }
.primary { color: {{ $design['color_primary'] }}; }
table.data { width: 100%; border-collapse: collapse; margin: 4pt 0 8pt; }
table.data th { text-align: left; font-size: {{ max(7, $design['font_size'] - 1.5) }}pt; color: {{ $design['color_muted'] }}; font-weight: bold; border-bottom: 1pt solid #D1D5DB; padding: 4pt 5pt; }
table.data td { border-bottom: 0.5pt solid #E5E7EB; padding: 4pt 5pt; vertical-align: top; }
table.data td.num, table.data th.num { text-align: right; }
table.kpis { width: 100%; border-collapse: separate; border-spacing: 6pt 0; margin: 6pt -6pt 10pt; }
table.kpis td { background: #F3F4F6; padding: 9pt 10pt; width: 25%; vertical-align: top; }
table.kpis .v { font-family: {{ $design['font_heading'] }}; font-size: 20pt; font-weight: bold; color: {{ $design['color_primary'] }}; }
table.kpis .l { font-size: {{ max(7, $design['font_size'] - 2) }}pt; color: {{ $design['color_muted'] }}; }
.score { font-weight: bold; padding: 1pt 5pt; }
.score-ok { color: #0F8A4B; } .score-warn { color: #B26B00; } .score-crit { color: #C81E1E; }
.ok { color: #0F8A4B; } .bad { color: #C81E1E; font-weight: bold; }
.site-head { background: {{ $design['color_accent'] }}; color: #FFFFFF; padding: 7pt 10pt; margin: 16pt 0 6pt; font-family: {{ $design['font_heading'] }}; font-weight: bold; font-size: 12pt; }
.site-head span { font-weight: normal; font-size: 9pt; }
.cover-text { color: {{ $design['cover_text_color'] }}; }
