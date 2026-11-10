@extends('layouts.app')
@section('title', 'Klanten importeren')
@section('content')
  <div class="page-head">
    <div>
      <h1>Klanten importeren</h1>
      <p>Zet klanten, contactgegevens en websites in één keer klaar. Daarna stel je per klant alleen de startdatum van het onderhoud in en koppel je per site de plugin.</p>
    </div>
    <div class="right row-actions">
      <a class="btn ghost" href="{{ route('customers.import.template') }}">Voorbeeldbestand (CSV)</a>
      <a class="btn ghost" href="{{ route('customers.index') }}">Terug naar klanten</a>
    </div>
  </div>

  @if ($plan === null)
    <div class="cols cols-wide">
      <form class="panel form" method="POST" action="{{ route('customers.import.preview') }}" enctype="multipart/form-data">
        @csrf
        <h3>Bestand of plakken</h3>
        <div class="stack">
          <label>CSV-bestand<input type="file" name="file" accept=".csv,.txt,.tsv,text/csv"></label>
          <label>Of plak hier de regels uit Excel (met de kolomnamen op de eerste regel)
            <textarea name="data" rows="10" class="mono" placeholder="Klant&#9;Contactpersoon&#9;E-mail&#9;Website">{{ old('data') }}</textarea>
          </label>
          <div class="actions"><button class="btn" type="submit">Inlezen en controleren</button></div>
          <p class="note">Er wordt nog niets opgeslagen: je ziet eerst wat er toegevoegd wordt.</p>
        </div>
      </form>

      <div class="panel">
        <h3>Kolommen</h3>
        <table>
          <tbody>
            <tr><td><b>Klant</b></td><td>verplicht. Zelfde naam op meerdere regels = één klant.</td></tr>
            <tr><td>Contactpersoon</td><td>naam voor de aanhef in de rapportmail</td></tr>
            <tr><td>E-mail</td><td>ontvanger van het rapport</td></tr>
            <tr><td>Telefoon</td><td></td></tr>
            <tr><td>CC</td><td>extra ontvangers, gescheiden door komma's</td></tr>
            <tr><td>Website</td><td>bijv. <code>bakkerij.nl</code> of <code>https://www.bakkerij.nl</code>. Meerdere sites: op één regel met spaties of komma's, of elk op een eigen regel.</td></tr>
            <tr><td>Onderhoud</td><td>0, 2, 4, 6 of 12 keer per jaar (mag leeg)</td></tr>
            <tr><td>Startdatum</td><td>dd-mm-jjjj (mag leeg, stel je later in)</td></tr>
            <tr><td>Notities</td><td></td></tr>
          </tbody>
        </table>
        <p class="note mt">Scheidingsteken (puntkomma, komma of tab) wordt vanzelf herkend. Bestaat een klant al (zelfde naam), dan worden alleen lege velden aangevuld. Sites die al in het panel staan worden niet dubbel aangemaakt.</p>
      </div>
    </div>
  @else
    @php($c = $plan['counts'])
    @if ($plan['errors'])
      <div class="alert warn mb"><ul class="issues">@foreach ($plan['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="panel">
      <div class="panel-head">
        <h3>Controleren</h3>
        <span class="hint">{{ $c['create'] }} nieuwe klant(en) · {{ $c['update'] }} bestaande aanvullen · {{ $c['sites'] }} nieuwe site(s){{ $c['attach'] ? ' · ' . $c['attach'] . ' bestaande site(s) koppelen aan klant' : '' }}{{ $c['skipped'] ? ' · ' . $c['skipped'] . ' site(s) overgeslagen' : '' }}</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Klant</th><th>Contact</th><th>Onderhoud</th><th>Websites</th></tr></thead>
          <tbody>
            @forelse ($plan['customers'] as $row)
              @php($f = $row['fields'])
              <tr>
                <td>
                  <b>{{ $row['name'] }}</b>
                  <span @class(['badge', 'ok' => $row['action'] === 'create'])>{{ $row['action'] === 'create' ? 'nieuw' : 'bestaat, aanvullen' }}</span>
                  @foreach ($row['warnings'] as $warning)<div class="note issue-text warn">{{ $warning }}</div>@endforeach
                </td>
                <td>
                  {{ $f['contact_name'] ?? '' }}
                  <div class="note">{{ collect([$f['email'] ?? null, $f['phone'] ?? null])->filter()->implode(' · ') ?: '–' }}</div>
                  @isset($f['report_cc'])<div class="note">cc {{ $f['report_cc'] }}</div>@endisset
                </td>
                <td>
                  {{ isset($f['visits_per_year']) ? \App\Models\Customer::VISITS[$f['visits_per_year']] : '–' }}
                  <div class="note">{{ isset($f['contract_start']) ? 'start ' . \Carbon\CarbonImmutable::parse($f['contract_start'])->format('d-m-Y') : 'startdatum later' }}</div>
                </td>
                <td>
                  @forelse ($row['sites'] as $site)
                    <div>
                      <span class="mono">{{ $site['host'] }}</span>
                      <span class="note">{{ ['create' => 'nieuw', 'attach' => 'bestaat, wordt aan klant gekoppeld', 'exists' => 'staat al bij deze klant', 'other' => 'staat al bij een andere klant, overgeslagen'][$site['action']] }}</span>
                    </div>
                  @empty
                    <span class="note">geen</span>
                  @endforelse
                </td>
              </tr>
            @empty
              <tr><td colspan="4" class="note">Niets gevonden om te importeren.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="row-actions mt">
        @if ($plan['customers'])
          <form method="POST" action="{{ route('customers.import.store') }}" class="inline">
            @csrf
            <input type="hidden" name="data" value="{{ $data }}">
            <button class="btn" type="submit">Importeren</button>
          </form>
        @endif
        <a class="btn ghost" href="{{ route('customers.import') }}">Ander bestand</a>
      </div>
      <p class="note mt">Sites worden toegevoegd zonder koppelcode. Open de site als je de plugin installeert en klik op "Nieuwe koppelcode maken".</p>
    </div>
  @endif
@endsection
