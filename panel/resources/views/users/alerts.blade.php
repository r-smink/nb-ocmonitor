@extends('layouts.app')
@section('title', 'Meldingen voor ' . $user->name)
@section('content')
  <div class="page-head">
    <div>
      <h1>Meldingen voor {{ $user->name }}</h1>
      <p>Per e-mail naar {{ $user->email }}. De gebruiker kan dit zelf ook aanpassen onder Account.</p>
    </div>
  </div>
  @include('settings.tabs')

  <section class="panel narrow">
    <form method="POST" action="{{ route('users.alerts.update', $user) }}" class="form">
      @csrf
      @method('PUT')
      @include('partials.alert-prefs', ['prefs' => $prefs, 'quiet' => true, 'enabledLabel' => 'Meldingen per e-mail'])
      <div class="actions">
        <button class="btn" type="submit">Opslaan</button>
        <a class="btn ghost" href="{{ route('users.index') }}">Annuleren</a>
      </div>
    </form>
  </section>
@endsection
