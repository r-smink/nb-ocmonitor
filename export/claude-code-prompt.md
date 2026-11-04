# Claude Code-opdracht — Onderhoudsrapport: nieuwe cover + vervolgpagina

Repo: **r-smink/nb-ocmonitor** · pad: `panel/`

## Doel
De automatisch gegenereerde PDF-rapporten (`App\Ocm\Reports\ReportPdf`) krijgen een nieuwe,
on-brand Next Buzz-voorpagina (donker, witte tekst) en vervolgpagina. Twee achtergrond-
afbeeldingen (A4, 2480 × 3508 px) worden als `ReportAsset` geüpload. Daarnaast rendert de
cover voortaan:
1. een **omkaderd "Rapportperiode"-blok** rechts naast de titel (uit bestaande `from`/`to`-data);
2. een **screenshot van de homepage na de laatste update** (de "visuele controle"), in een
   browser-kader onder de titel.

Alle opmaak draait door **mPDF** — dus **geen flexbox/grid**, alleen tabellen, block-divs en
inline-styles (zie bestaande `resources/views/reports/pdf/*.blade.php`).

## Bijgeleverde assets
- `background_cover.png` (2480 × 3508) → uploaden als ReportAsset-kind **`background_cover`**
- `background_page.png` (2480 × 3508) → uploaden als ReportAsset-kind **`background_page`**

Upload kan via de bestaande Rapporten-pagina (`ReportController@uploadAsset`), of met een seeder.
De `@page`/`@page :first` regels in `reports/pdf/style.blade.php` leggen deze automatisch onder
elke pagina — daar hoeft niets aan te veranderen.

## 1. Witte covertekst
In `app/Ocm/Reports/ReportDesign.php`, `DEFAULTS`:
```php
'cover_text_color' => '#FFFFFF',
```
(Of laat de default staan en zet het één keer via de Rapporten-instellingen op `#FFFFFF`.)

## 2. Screenshot aanleveren aan de view
De cover-screenshot is de laatst bekende homepage-opname van de **eerste** gekoppelde site
(de "visuele controle na updates"). Zoek de bestaande bron hiervan op (model/opslag voor de
visuele controle — waarschijnlijk een snapshot-model of een bestand in Spaces/`storage`) en
lever het **als los bestand op schijf** aan, net zoals `ReportPdf` nu al met logo/achtergronden doet.

In `app/Ocm/Reports/ReportData.php` → `build()`, voeg aan de return-array toe:
```php
'cover_shot' => optional($sites->first())->latestVisualShotPath(), // pas aan naar de echte bron
```
> Vervang `latestVisualShotPath()` door de werkelijke accessor/relatie. Geef `null` terug als er
> geen opname is — de cover laat het kader dan weg.

In `app/Ocm/Reports/ReportPdf.php` → `render()`, materialiseer de opname naar een temp-bestand
(mPDF leest images van een pad) en voeg hem toe aan `$view`:
```php
$coverShot = null;
if (!empty($data['cover_shot']) && is_file($data['cover_shot'])) {
    $coverShot = $data['cover_shot']; // al een leesbaar pad
}
// ... bestaande $view-array:
$view = ['d' => $data, 'design' => $design, 'logo' => $files['logo'] ?? null,
    'coverShot' => $coverShot,
    'coverImage' => ...,
    'pageImage'  => ...];
```
Vul in `demoData()` ook een `'cover_shot'`-pad met een voorbeeldafbeelding, zodat de
voorbeeld-PDF op de Rapporten-pagina de cover compleet toont.

## 3. Nieuwe cover — `resources/views/reports/pdf/cover.blade.php`
Vervang de inhoud volledig door onderstaande (mPDF-veilig; witte tekst op de donkere
achtergrond-afbeelding):

```blade
@php($fmt = fn ($date) => $date->locale('nl')->translatedFormat('j M Y'))
<div style="padding: 28mm {{ $design['margin_side'] }}mm 0; color: {{ $design['cover_text_color'] }};">
  @if ($logo)<img src="{{ $logo }}" style="max-height: 18mm; max-width: 60mm;">@endif
  <div style="height: 16mm;"></div>

  {{-- Titel links, periode-kader rechts --}}
  <table width="100%" style="border-collapse: collapse;"><tr>
    <td style="vertical-align: top; width: 62%;">
      <p class="primary" style="font-size: 10pt; font-weight: bold; letter-spacing: 1.4pt; text-transform: uppercase; color: {{ $design['color_primary'] }};">{{ $design['title'] }}</p>
      <h1 style="color: {{ $design['cover_text_color'] }}; font-size: 26pt; line-height: 1.1; margin: 2pt 0 0;">{{ $d['customer']['name'] }}</h1>
      <p style="font-size: 10.5pt; margin-top: 7pt; color: #9B9B98;">{{ $d['customer']['contract'] }}@if ($d['sites']) · {{ count($d['sites']) }} {{ count($d['sites']) === 1 ? 'website' : 'websites' }}@endif</p>
    </td>
    <td style="vertical-align: top; width: 38%; padding-left: 10mm;">
      <div style="border: 0.75pt solid #3C3C3C; border-radius: 10pt; padding: 11pt 13pt;">
        <div style="width: 26pt; height: 2.5pt; background: {{ $design['color_primary'] }};"></div>
        <p style="font-size: 7.5pt; font-weight: bold; letter-spacing: 1.2pt; text-transform: uppercase; color: #9B9B98; margin: 7pt 0 5pt;">Rapportperiode</p>
        <p style="font-size: 15pt; font-weight: bold; line-height: 1.15; margin: 0; color: {{ $design['cover_text_color'] }};">{{ $fmt($d['from']) }} –</p>
        <p style="font-size: 15pt; font-weight: bold; line-height: 1.15; margin: 0; color: {{ $design['color_primary'] }};">{{ $fmt($d['to']) }}</p>
      </div>
    </td>
  </tr></table>

  {{-- Screenshot na updates --}}
  @if (!empty($coverShot))
    <div style="height: 10mm;"></div>
    <div style="border: 0.75pt solid #3C3C3C; border-radius: 9pt; overflow: hidden;">
      <div style="background: #242424; padding: 5pt 9pt;">
        <span style="color: #9B9B98; font-size: 7.5pt;">● ● ●&nbsp;&nbsp;&nbsp;{{ $d['sites'][0]['url'] ?? '' }}</span>
      </div>
      <img src="{{ $coverShot }}" style="width: 100%; display: block;">
    </div>
    <p style="font-size: 7.5pt; color: #9B9B98; margin-top: 5pt;">Visuele controle van de homepage na de laatste update.</p>
  @endif
</div>
```

Let op: de **contactregel onderaan** (Brabantsestraat 17 · 033 760 1960 · nextbuzz.nl) en de
grote transparante rode pijl zitten al **in de achtergrond-afbeelding** `background_cover.png`
— die hoef je niet in Blade te zetten.

## 4. Vervolgpagina
De vervolgpagina-achtergrond (`background_page.png`) bevat de gebrande header-band en de
voetlijn. `resources/views/reports/pdf/body.blade.php` en `footer.blade.php` blijven
ongewijzigd; controleer alleen dat `margin_top` ≥ 30 mm zodat de content onder de header-band
begint (staat al zo in `ReportDesign::DEFAULTS`).

## 5. Controleren
```bash
cd panel && php artisan test
```
Genereer daarna een voorbeeld-PDF via de Rapporten-pagina (`ReportController@preview`) en
controleer: witte titel + periode-kader op de cover, screenshot in het browser-kader, en de
vervolgpagina met header-band + voetlijn.
