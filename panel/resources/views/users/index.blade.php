@extends('layouts.app')
@section('title', 'Portalgebruikers')
@section('content')
  <div class="page-head">
    <div>
      <h1>Instellingen</h1>
      <p>Portalgebruikers: wie kan inloggen op OC Monitor. Iedereen logt in met wachtwoord en verplichte tweestapsverificatie.</p>
    </div>
  </div>
  <nav class="tabs-nav mt">
    <a href="{{ route('settings.show') }}" @class(['on' => request()->routeIs('settings.*')])>Algemeen en menu</a>
    <a href="{{ route('users.index') }}" @class(['on' => request()->routeIs('users.*')])>Portalgebruikers</a>
  </nav>

  <div class="panel">
    <div class="table-wrap">
      <table>
        <thead><tr><th>Naam</th><th>E-mail</th><th>Rol</th><th>2FA</th><th>Laatste login</th><th></th></tr></thead>
        <tbody>
          @foreach ($users as $user)
            <tr>
              <td>{{ $user->name }}</td>
              <td>{{ $user->email }}</td>
              <td>
                <form method="POST" action="{{ route('users.update', $user) }}" class="row-actions">
                  @csrf
                  @method('PUT')
                  <select name="role" class="w-auto">
                    @foreach ($roles as $value => $label)
                      <option value="{{ $value }}" @selected($user->role === $value)>{{ $label }}</option>
                    @endforeach
                  </select>
                  <button class="btn ghost small" type="submit">Opslaan</button>
                </form>
              </td>
              <td>@if ($user->hasTwoFactor())<span class="state">aan</span>@else<span class="state idle">nog instellen</span>@endif</td>
              <td>{{ $user->last_login_at?->timezone('Europe/Amsterdam')->format('d-m-Y H:i') ?? '–' }}</td>
              <td class="actions">
                @if ($user->hasTwoFactor())
                  <form method="POST" action="{{ route('users.reset-2fa', $user) }}" class="inline" data-confirm="2FA van {{ $user->email }} resetten?">
                    @csrf
                    <button class="btn ghost small" type="submit">2FA resetten</button>
                  </form>
                @endif
                @unless ($user->is(auth()->user()))
                  <form method="POST" action="{{ route('users.destroy', $user) }}" class="inline" data-confirm="Account {{ $user->email }} verwijderen?">
                    @csrf
                    @method('DELETE')
                    <button class="btn danger small" type="submit">Verwijderen</button>
                  </form>
                @endunless
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel narrow">
    <h3>Gebruiker toevoegen</h3>
    <form class="form" method="POST" action="{{ route('users.store') }}">
      @csrf
      <div class="row">
        <label>Naam<input name="name" value="{{ old('name') }}" required maxlength="120"></label>
        <label>E-mailadres<input name="email" type="email" value="{{ old('email') }}" required maxlength="191"></label>
      </div>
      <div class="row">
        <label>Rol
          <select name="role">
            @foreach ($roles as $value => $label)
              <option value="{{ $value }}" @selected(old('role', 'beheerder') === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </label>
        <label>Tijdelijk wachtwoord (min. 12 tekens)<input name="password" type="password" autocomplete="new-password" required minlength="12"></label>
      </div>
      <p class="note">Admin: alles, inclusief gebruikersbeheer. Beheerder: sites toevoegen en opdrachten geven. Alleen lezen: alleen bekijken.</p>
      <div class="actions"><button class="btn" type="submit">Toevoegen</button></div>
    </form>
  </div>
@endsection
