@extends('layouts.app')
@section('title', 'Site toevoegen')
@section('content')
  <div class="dhead"><a class="back" href="{{ route('sites.index') }}" aria-label="Terug">←</a><h2>Site toevoegen</h2></div>
  <div class="cols">
    <div class="panel">
      <h3>Gegevens</h3>
      <form class="form" method="POST" action="{{ route('sites.store') }}">
        @csrf
        <label>Naam<input name="name" value="{{ old('name') }}" required maxlength="120" autofocus></label>
        <label>Klant (optioneel)<input name="client_name" value="{{ old('client_name') }}" maxlength="120"></label>
        <label>URL van de site<input name="url" type="url" value="{{ old('url') }}" placeholder="https://www.klant.nl" required maxlength="255"></label>
        <p class="note">Gebruik exact de URL uit Instellingen → Algemeen → Siteadres (home_url). Alleen die host kan de koppelcode gebruiken.</p>
        <div class="actions"><button class="btn" type="submit">Aanmaken en koppelcode tonen <span class="arrow" aria-hidden="true">→</span></button></div>
      </form>
    </div>
    <div class="panel">
      <h3>Zo werkt koppelen</h3>
      <ol class="steps">
        <li>Maak hier de site aan. Je krijgt een koppelcode die 15 minuten geldig is.</li>
        <li><a href="{{ route('plugin.download') }}">Download de client-plugin</a>{{ $pluginVersion ? ' (v' . $pluginVersion . ')' : '' }} en installeer hem op de site via Plugins → Nieuwe plugin → Plugin uploaden.</li>
        <li>Ga op de site naar Extra → NB OC Monitor en plak de koppelcode. Of gebruik WP-CLI: <code>wp nb-ocm pair &lt;code&gt;</code>.</li>
        <li>De site maakt een eigen sleutelpaar en stuurt alleen de publieke sleutel naar het panel. Daarna verschijnt de site hier als gekoppeld.</li>
      </ol>
    </div>
  </div>
@endsection
