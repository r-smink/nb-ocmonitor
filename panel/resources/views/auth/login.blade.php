@extends('layouts.guest')
@section('content')
  <p class="lead">Log in om de WordPress-sites van Next Buzz te beheren.</p>
  <form method="POST" action="{{ url('/login') }}">
    @csrf
    <label>E-mailadres<input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label>
    <label>Wachtwoord<input name="password" type="password" autocomplete="current-password" required></label>
    @error('email')<div class="form-error">{{ $message }}</div>@enderror
    <button class="btn block" type="submit">Verder <span class="arrow" aria-hidden="true">→</span></button>
  </form>
@endsection
