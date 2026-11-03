<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('title', 'Sites') — {{ \App\Ocm\AppSettings::get('app_name') ?: 'OC Monitor' }}</title>
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <script src="/js/theme.js"></script>
  <link rel="stylesheet" href="/css/ds/styles.css">
  <link rel="stylesheet" href="/css/serverdash.css">
  <link rel="stylesheet" href="/css/ocm.css">
  <script src="/js/app.js" defer></script>
</head>
<body class="app-shell">
  @php($navCounts = \App\Ocm\Menu::counts())
  <aside class="sidebar" id="sidebar" aria-label="Hoofdmenu">
    <a class="brand" href="{{ route('dashboard') }}"><span class="logo" role="img" aria-label="Next Buzz"></span>{{ \App\Ocm\AppSettings::get('app_name') ?: 'OC Monitor' }}</a>
    <form class="side-search" method="GET" action="{{ route('sites.index') }}" role="search">
      @include('partials.icon', ['name' => 'search', 'size' => 16])
      <input type="search" name="q" value="{{ request()->routeIs('sites.index') ? request('q') : '' }}" placeholder="Zoek site of klant…" aria-label="Zoek site of klant">
    </form>
    <nav class="side-nav">
      @foreach (\App\Ocm\Menu::grouped() as $group => $items)
        <span class="side-group">{{ $group }}</span>
        @foreach ($items as $item)
          @php($count = ['updates' => $navCounts['updates'], 'vulnerabilities' => $navCounts['vulnerabilities']][$item['key']] ?? 0)
          <a href="{{ route($item['route']) }}" @class(['active' => request()->routeIs(...$item['patterns'])]) @if (request()->routeIs(...$item['patterns'])) aria-current="page" @endif>
            @include('partials.icon', ['name' => $item['icon'], 'size' => 17])
            <span class="lbl">{{ $item['label'] }}</span>
            @if ($count > 0)<span @class(['nav-count', 'crit' => $item['key'] === 'vulnerabilities'])>{{ $count }}</span>@endif
          </a>
        @endforeach
        @if ($group === 'Beheer')
          @can('manage-users')
            <a href="{{ route('settings.show') }}" @class(['active' => request()->routeIs('settings.*', 'users.*')]) @if (request()->routeIs('settings.*', 'users.*')) aria-current="page" @endif>
              @include('partials.icon', ['name' => 'settings', 'size' => 17])<span class="lbl">Instellingen</span>
            </a>
          @endcan
        @endif
      @endforeach
    </nav>
    <div class="side-foot">
      <span @class(['conn', 'err' => $navCounts['connected'] < $navCounts['paired']])>{{ $navCounts['connected'] }} van {{ $navCounts['paired'] }} sites verbonden</span>
      <div class="side-links">
        <a href="{{ route('plugin.download') }}" title="Download de client-plugin voor WordPress">@include('partials.icon', ['name' => 'download', 'size' => 15]) Plugin</a>
        <form method="POST" action="{{ route('logout') }}" class="inline">
          @csrf
          <button type="submit">@include('partials.icon', ['name' => 'logout', 'size' => 15]) Uitloggen</button>
        </form>
      </div>
    </div>
  </aside>

  <div class="shell-main">
    <header class="appbar">
      <button class="icon-btn nav-toggle" type="button" data-nav-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Menu openen">@include('partials.icon', ['name' => 'grid', 'size' => 18])</button>
      <div class="appbar-title">@yield('title', 'Sites')</div>
      <div class="appbar-right">
        <button id="themeToggle" class="theme-pill" type="button" aria-label="Wissel tussen licht en donker thema">
          <span class="to-dark">@include('partials.icon', ['name' => 'moon', 'size' => 15]) Donker</span>
          <span class="to-light">@include('partials.icon', ['name' => 'sun', 'size' => 15]) Licht</span>
        </button>
        <a class="icon-btn" href="{{ route('dashboard') }}#aandachtspunten" title="Aandachtspunten" aria-label="Aandachtspunten">@include('partials.icon', ['name' => 'bell', 'size' => 18])</a>
        @can('manage-sites')
          <a class="btn small" href="{{ route('sites.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 15]) Site</a>
        @endcan
        <a href="{{ route('account.show') }}" @class(['avatar', 'active' => request()->routeIs('account.*')]) title="{{ auth()->user()->name }}" aria-label="Account van {{ auth()->user()->name }}">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</a>
      </div>
    </header>
    <main @hasSection('refresh') data-refresh="@yield('refresh')" @endif>
      @include('partials.flash')
      @yield('content')
    </main>
  </div>
</body>
</html>
