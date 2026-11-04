<nav class="tabs-nav mt">
  <a href="{{ route('settings.show') }}" @class(['on' => request()->routeIs('settings.show')])>Algemeen en menu</a>
  <a href="{{ route('settings.alerts') }}" @class(['on' => request()->routeIs('settings.alerts*')])>Meldingen</a>
  <a href="{{ route('users.index') }}" @class(['on' => request()->routeIs('users.*')])>Portalgebruikers</a>
  <a href="{{ route('settings.api') }}" @class(['on' => request()->routeIs('settings.api*')])>API</a>
</nav>
