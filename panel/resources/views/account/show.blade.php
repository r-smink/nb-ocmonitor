@extends('layouts.app')
@section('title', 'Account')
@section('content')
  <div class="page-head">
    <div>
      <h1>Account</h1>
      <p>{{ $user->name }} · {{ $user->email }} · {{ \App\Models\User::ROLES[$user->role] ?? $user->role }}</p>
    </div>
  </div>

  <div class="cols">
    <div class="panel">
      <h3>Wachtwoord wijzigen</h3>
      <form class="form" method="POST" action="{{ route('account.password') }}">
        @csrf
        @method('PUT')
        <label>Huidig wachtwoord<input name="current_password" type="password" autocomplete="current-password" required></label>
        <label>Nieuw wachtwoord (min. 12 tekens)<input name="password" type="password" autocomplete="new-password" required minlength="12"></label>
        <label>Nieuw wachtwoord herhalen<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="12"></label>
        <div class="actions"><button class="btn" type="submit">Wijzigen</button></div>
      </form>
    </div>
    <div class="panel">
      <h3>Tweestapsverificatie</h3>
      <dl class="kv">
        <dt>Status</dt><dd>{{ $user->hasTwoFactor() ? 'Aan sinds ' . $user->totp_confirmed_at->timezone('Europe/Amsterdam')->format('d-m-Y') : 'Niet ingesteld' }}</dd>
        <dt>Laatste login</dt><dd>{{ $user->last_login_at?->timezone('Europe/Amsterdam')->format('d-m-Y H:i') ?? '–' }}</dd>
      </dl>
      <p class="note mt">Nieuwe telefoon? Vraag een admin om je 2FA te resetten. Bij de volgende login stel je hem opnieuw in.</p>
    </div>
  </div>
@endsection
