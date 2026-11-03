@extends('layouts.app')
@section('title', 'WP-gebruikers')
@section('content')
  <div class="page-head">
    <div>
      <h1>WP-gebruikers</h1>
      <p>Beheerders, redacteuren en winkelmanagers op alle gekoppelde sites (client-plugin 0.4.0 of nieuwer). Het panel kan geen gebruikers aanmaken en geen wachtwoorden kiezen.</p>
    </div>
  </div>

  <div class="kpis">
    <div @class(['kpi', 'lvl-none' => $totals['admins'] === 0])>
      <span class="kpi-head"><span class="kpi-ic">@include('partials.icon', ['name' => 'users', 'size' => 20])</span>Beheerders</span>
      <b>{{ $totals['admins'] }}</b>
      <small>met de rol administrator, op alle sites</small>
    </div>
    <a href="{{ route('wp-users.index', ['twofa' => 'zonder']) }}" @class(['kpi', 'lvl-warn' => $totals['without_2fa'] > 0])>
      <span class="kpi-head"><span class="kpi-ic">@include('partials.icon', ['name' => 'shield', 'size' => 20])</span>Zonder 2FA</span>
      <b>{{ $totals['without_2fa'] }}</b>
      <small>{{ $totals['without_2fa'] > 0 ? 'stel tweestapsverificatie in' : 'iedereen gebruikt 2FA' }}</small>
    </a>
    <div class="kpi lvl-none">
      <span class="kpi-head"><span class="kpi-ic">@include('partials.icon', ['name' => 'lock', 'size' => 20])</span>Geblokkeerd</span>
      <b>{{ $totals['locked'] }}</b>
      <small>{{ $totals['unknown'] > 0 ? $totals['unknown'] . ' site(s) zonder gegevens' : 'accounts die niet kunnen inloggen' }}</small>
    </div>
  </div>

  @can('manage-sites')
    <div class="cols">
      <section class="panel">
        <div class="panel-head"><h3>Tweestapsverificatie op alle sites</h3></div>
        <form method="POST" action="{{ route('wp-users.two-factor') }}" class="row-actions" data-confirm="Dit beleid instellen op alle sites met client-plugin 0.4.0 of nieuwer?">
          @csrf
          <select name="mode" class="w-auto" aria-label="Beleid">
            @foreach (\App\Http\Controllers\WpUserController::TWO_FACTOR_MODES as $mode => $label)<option value="{{ $mode }}" @selected($mode === 'admins')>{{ $label }}</option>@endforeach
          </select>
          <button class="btn small" type="submit">Instellen op alle sites</button>
        </form>
        <p class="note mt">Wie al een 2FA-plugin gebruikt (Two Factor, Wordfence, WP 2FA) merkt niets. Anderen krijgen na het wachtwoord een code per e-mail; de site moet dus mail kunnen versturen. Noodstop op de site: <code>define('NB_OCM_DISABLE_2FA', true);</code></p>
      </section>
      <section class="panel">
        <div class="panel-head"><h3>Gegevens bijwerken</h3></div>
        <form method="POST" action="{{ route('wp-users.refresh') }}">
          @csrf
          <button class="btn ghost small" type="submit">Gebruikers nu ophalen</button>
        </form>
        <p class="note mt">Gebeurt ook elke nacht en na elke actie. {{ $totals['unknown'] > 0 ? $totals['unknown'] . ' site(s) zonder gegevens (nog niet opgehaald of plugin ouder dan 0.4.0).' : '' }}</p>
      </section>
    </div>
  @endcan

  <div class="toolbar">
    <form class="filters" method="GET" action="{{ route('wp-users.index') }}">
      @include('partials.tag-select')
      <select name="site" data-autosubmit aria-label="Website">
        <option value="">Alle websites</option>
        @foreach ($sites as $site)<option value="{{ $site->site_id }}" @selected($filters['site'] === $site->site_id)>{{ $site->name }}</option>@endforeach
      </select>
      <select name="role" data-autosubmit aria-label="Rol">
        <option value="">Alle rollen</option>
        @foreach (\App\Http\Controllers\WpUserController::ROLES as $role => $label)<option value="{{ $role }}" @selected($filters['role'] === $role)>{{ $label }}</option>@endforeach
      </select>
      <select name="twofa" data-autosubmit aria-label="2FA">
        <option value="">Met en zonder 2FA</option>
        <option value="zonder" @selected($filters['twofa'] === 'zonder')>Zonder 2FA</option>
      </select>
      <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Zoek gebruiker, e-mail of site…" aria-label="Zoeken">
      @if (array_filter($filters))<a class="btn ghost small" href="{{ route('wp-users.index') }}">Wis filters</a>@endif
    </form>
  </div>

  <section class="panel">
    <div class="panel-head"><h3>Gebruikers</h3><span class="badge">{{ $rows->count() }}</span></div>
    @include('partials.wp-users-table', ['rows' => $rows, 'showSite' => true])
  </section>
@endsection
