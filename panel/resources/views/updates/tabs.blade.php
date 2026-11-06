@php
  $openUpdates = \App\Models\SiteJob::query()->whereIn('command', array_values(\App\Ocm\Updates::COMMANDS))->whereIn('status', ['pending', 'running'])->count();
@endphp
<nav class="tabs-nav mt mb">
  <a href="{{ route('updates.index') }}" @class(['on' => request()->routeIs('updates.index')])>Beschikbare updates</a>
  <a href="{{ route('updates.queue') }}" @class(['on' => request()->routeIs('updates.queue')])>Wachtrij @if ($openUpdates > 0)<span class="count-pill crit">{{ $openUpdates }}</span>@endif</a>
  <a href="{{ route('updates.schedules.index') }}" @class(['on' => request()->routeIs('updates.schedules.*')])>Automatisch (planning)</a>
</nav>
