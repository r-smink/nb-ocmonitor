@extends('layouts.guest')
@section('title', 'Tweestapsverificatie instellen')
@section('content')
  <p class="lead">Tweestapsverificatie is verplicht. Scan de QR-code met een authenticator-app (bijvoorbeeld Microsoft Authenticator, 1Password of Google Authenticator).</p>
  <div class="qr">{!! $qr !!}</div>
  <p class="note">Lukt scannen niet? Voer deze sleutel handmatig in:<br><span class="secret">{{ $secret }}</span></p>
  <form method="POST" action="{{ url('/login/2fa/instellen') }}">
    @csrf
    <label>Code uit de app<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></label>
    @error('code')<div class="form-error">{{ $message }}</div>@enderror
    <button class="btn block" type="submit">Bevestigen en inloggen <span class="arrow" aria-hidden="true">→</span></button>
  </form>
@endsection
