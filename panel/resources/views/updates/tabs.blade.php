<nav class="tabs-nav mt mb">
  <a href="{{ route('updates.index') }}" @class(['on' => request()->routeIs('updates.index')])>Beschikbare updates</a>
  <a href="{{ route('updates.schedules.index') }}" @class(['on' => request()->routeIs('updates.schedules.*')])>Automatisch (planning)</a>
</nav>
