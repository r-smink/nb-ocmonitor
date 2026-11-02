<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('title', 'Sites') — OC Monitor</title>
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <script src="/js/theme.js"></script>
  <link rel="stylesheet" href="/css/ds/styles.css">
  <link rel="stylesheet" href="/css/serverdash.css">
  <link rel="stylesheet" href="/css/ocm.css">
  <script src="/js/app.js" defer></script>
</head>
<body>
  <header class="topbar">
    <a class="brand" href="{{ route('dashboard') }}"><span class="logo" role="img" aria-label="Next Buzz"></span>OC Monitor</a>
    <nav>
      <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Overzicht</a>
      <a href="{{ route('sites.index') }}" @class(['active' => request()->routeIs('sites.*')])>Sites</a>
      @php($navUpdates = (int) \App\Models\Site::query()->whereNotNull('public_key')->sum('updates_count'))
      <a href="{{ route('updates.index') }}" @class(['active' => request()->routeIs('updates.*')])>Updates @if ($navUpdates > 0)<span class="nav-badge">{{ $navUpdates }}</span>@endif</a>
      <a href="{{ route('pagespeed.index') }}" @class(['active' => request()->routeIs('pagespeed.*')])>PageSpeed</a>
      <a href="{{ route('activity.index') }}" @class(['active' => request()->routeIs('activity.*')])>Activiteit</a>
      @can('manage-users')
        <a href="{{ route('users.index') }}" @class(['active' => request()->routeIs('users.*')])>Gebruikers</a>
      @endcan
    </nav>
    <div class="top-right">
      <a href="{{ route('plugin.download') }}" class="btn ghost small" title="Download de client-plugin voor WordPress">Plugin</a>
      <a href="{{ route('account.show') }}" class="btn ghost small">{{ auth()->user()->name }}</a>
      <button id="themeToggle" class="theme-toggle" type="button" aria-label="Wissel tussen licht en donker thema">
        <svg class="sun" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#E8A317" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="4"></circle><path d="M12 3v1.5M12 19.5V21M3 12h1.5M19.5 12H21M5.6 5.6l1 1M17.4 17.4l1 1M18.4 5.6l-1 1M6.6 17.4l-1 1"></path></svg>
        <svg class="moon" width="12" height="12" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"></path></svg>
        <span class="knob"></span>
      </button>
      <form method="POST" action="{{ route('logout') }}" class="inline">
        @csrf
        <button class="btn ghost small" type="submit">Uitloggen</button>
      </form>
    </div>
  </header>
  <main @hasSection('refresh') data-refresh="@yield('refresh')" @endif>
    @include('partials.flash')
    @yield('content')
  </main>
</body>
</html>
