@extends('layouts.app')
@section('title', 'Rapporten')
@section('content')
  <div class="page-head">
    <div>
      <h1>Rapporten</h1>
      <p>Opmaak van de onderhoudsrapporten (PDF) voor klanten. Welke onderdelen een klant krijgt, stel je per klant in.</p>
    </div>
    <div class="right row-actions">
      <a class="btn ghost" href="{{ route('reports.preview') }}" target="_blank" rel="noopener">Voorbeeld met demogegevens</a>
      @if ($customers->isNotEmpty())
        <form method="GET" action="{{ route('reports.preview') }}" target="_blank" class="filters">
          <select name="customer" data-autosubmit aria-label="Voorbeeld voor klant">
            <option value="">Voorbeeld voor klant…</option>
            @foreach ($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}</option>@endforeach
          </select>
        </form>
      @endif
    </div>
  </div>

  @php($canEdit = auth()->user()->can('manage-sites'))
  <div class="cols cols-wide">
    <form class="panel form" method="POST" action="{{ route('reports.update') }}">
      @csrf
      @method('PUT')
      <h3>Opmaak</h3>
      <fieldset class="stack" @disabled(!$canEdit)>
        <div class="form-row">
          <label>Bedrijfsnaam<input name="company" value="{{ $design['company'] }}" maxlength="200"></label>
          <label>Titel<input name="title" value="{{ $design['title'] }}" maxlength="200"></label>
        </div>
        <label>Inleiding (bij de samenvatting)<textarea name="intro" rows="3" maxlength="2000">{{ $design['intro'] }}</textarea></label>
        <label>Voettekst<input name="footer" value="{{ $design['footer'] }}" maxlength="200"></label>
        <label>Naam op de voorpagina, in de voettekst en de bestandsnaam
          <select name="report_name">
            @foreach (\App\Ocm\Reports\ReportDesign::REPORT_NAMES as $value => $label)
              <option value="{{ $value }}" @selected($design['report_name'] === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </label>

        <h4 class="mt">E-mail bij het rapport</h4>
        <label>Onderwerp<input name="mail_subject" value="{{ $design['mail_subject'] }}" maxlength="200"></label>
        <label>Tekst<textarea name="mail_body" rows="9" maxlength="5000">{{ $design['mail_body'] }}</textarea></label>
        <p class="note">Invulvelden:
          @foreach (\App\Ocm\Reports\ReportDesign::PLACEHOLDERS as $tag => $help)<code>{{ $tag }}</code> {{ $help }}{{ $loop->last ? '.' : ' · ' }}@endforeach
          Leeg laten = de standaardtekst.</p>

        <h4 class="mt">Kleuren</h4>
        <div class="form-row">
          @foreach (['color_primary' => 'Hoofdkleur', 'color_accent' => 'Koppen en balken', 'color_text' => 'Tekst', 'color_muted' => 'Lichte tekst', 'cover_text_color' => 'Tekst voorpagina'] as $key => $label)
            <label>{{ $label }}<span class="swatch"><input type="color" name="{{ $key }}" value="{{ $design[$key] }}"><span class="mono">{{ $design[$key] }}</span></span></label>
          @endforeach
        </div>

        <h4 class="mt">Lettertypes</h4>
        <div class="form-row">
          @foreach (['font_heading' => 'Koppen', 'font_body' => 'Tekst'] as $key => $label)
            <label>{{ $label }}
              <select name="{{ $key }}">
                @foreach (\App\Ocm\Reports\ReportDesign::FONTS as $value => $name)
                  <option value="{{ $value }}" @selected($design[$key] === $value) @disabled($value === 'custom' && !isset($assets['font_regular']))>{{ $name }}</option>
                @endforeach
              </select>
            </label>
          @endforeach
          <label>Tekstgrootte (pt)<input type="number" name="font_size" value="{{ $design['font_size'] }}" min="8" max="14"></label>
        </div>

        <h4 class="mt">Marges (mm)</h4>
        <div class="form-row">
          <label>Boven<input type="number" name="margin_top" value="{{ $design['margin_top'] }}" min="5" max="80"></label>
          <label>Onder<input type="number" name="margin_bottom" value="{{ $design['margin_bottom'] }}" min="5" max="80"></label>
          <label>Links en rechts<input type="number" name="margin_side" value="{{ $design['margin_side'] }}" min="5" max="80"></label>
        </div>
        <p class="note">Gebruik je een achtergrond met een logo of balk bovenaan, maak de bovenmarge dan zo groot dat de tekst eronder begint.</p>
        @if ($canEdit)<div class="actions"><button class="btn" type="submit">Opmaak opslaan</button></div>@endif
      </fieldset>
    </form>

    <div class="panel">
      <h3>Logo, achtergronden en fonts</h3>
      <p class="note">Achtergronden: een PDF (de eerste pagina wordt gebruikt) of een JPG/PNG op A4-formaat (2480 × 3508 px). Fonts: TrueType (.ttf). Maximaal 10 MB per bestand.</p>
      <div class="asset-list mt">
        @foreach (\App\Models\ReportAsset::KINDS as $kind => $label)
          @php($asset = $assets[$kind] ?? null)
          <div class="asset">
            <b>{{ $label }}</b>
            @if ($asset)
              <span class="muted">{{ $asset->filename }} · {{ number_format($asset->size / 1024, 0, ',', '.') }} kB</span>
              @if ($canEdit)
                <form method="POST" action="{{ route('reports.asset.destroy', $kind) }}" class="inline" data-confirm="{{ $label }} verwijderen?">
                  @csrf
                  @method('DELETE')
                  <button class="btn ghost small" type="submit">Verwijderen</button>
                </form>
              @endif
            @else
              <span class="muted">geen</span>
            @endif
            @if ($canEdit)
              <form method="POST" action="{{ route('reports.upload') }}" enctype="multipart/form-data" class="row-actions">
                @csrf
                <input type="hidden" name="kind" value="{{ $kind }}">
                <input type="file" name="file" required accept="{{ str_starts_with($kind, 'font_') ? '.ttf' : ($kind === 'logo' ? 'image/png,image/jpeg' : 'application/pdf,image/png,image/jpeg') }}" aria-label="{{ $label }}">
                <button class="btn ghost small" type="submit">{{ $asset ? 'Vervangen' : 'Uploaden' }}</button>
              </form>
            @endif
          </div>
        @endforeach
      </div>
      <p class="note mt">De voorpagina gebruikt de achtergrond voor de voorpagina; alle andere pagina's de tweede achtergrond. Zonder achtergrond blijft de pagina wit.</p>
    </div>
  </div>
@endsection
