{{-- Actieknop per rij. $action uit App\Ocm\IssueActions; $primary = rode knop (kritiek), anders omlijnd. --}}
@php($cls = ['btn', 'small', 'quiet' => !($primary ?? false)])
@if ($action['kind'] === 'form')
  <form method="POST" action="{{ $action['action'] }}" class="row-form" @if ($action['confirm']) data-confirm="{{ $action['confirm'] }}" @endif>
    @csrf
    @foreach ($action['fields'] as $name => $value)
      @foreach ((array) $value as $v)
        <input type="hidden" name="{{ is_array($value) ? $name . '[]' : $name }}" value="{{ $v }}">
      @endforeach
    @endforeach
    <button type="submit" @class($cls)>{{ $action['label'] }}</button>
  </form>
@elseif ($action['kind'] === 'done')
  <span class="btn small done" role="status">@include('partials.icon', ['name' => 'check', 'size' => 14]) {{ $action['label'] }}</span>
@else
  <a href="{{ $action['url'] }}" @class($cls)>{{ $action['label'] }}</a>
@endif
