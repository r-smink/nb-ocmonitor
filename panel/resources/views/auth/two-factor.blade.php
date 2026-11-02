@extends('layouts.guest')
@section('title', 'Verificatie')
@section('content')
  <p class="lead">Voer de 6-cijferige code uit je authenticator-app in.</p>
  <form method="POST" action="{{ url('/login/2fa') }}">
    @csrf
    <label>Verificatiecode<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></label>
    @error('code')<div class="form-error">{{ $message }}</div>@enderror
    <button class="btn block" type="submit">Inloggen <span class="arrow" aria-hidden="true">→</span></button>
  </form>
  <p class="note">Telefoon kwijt? Vraag een admin om je 2FA te resetten.</p>
@endsection
