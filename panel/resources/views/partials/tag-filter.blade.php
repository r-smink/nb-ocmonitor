{{-- Losse tagfilter voor pagina's zonder eigen filterformulier; behoudt de andere queryparameters. --}}
@if (\App\Models\Tag::query()->exists())
  <div class="toolbar">
    <form class="filters" method="GET" action="{{ url()->current() }}">
      @foreach (request()->except(['tag', 'page']) as $key => $value)
        @if (is_string($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
      @endforeach
      @include('partials.tag-select')
      @if (request('tag'))<a class="btn ghost small" href="{{ url()->current() . (request()->except(['tag', 'page']) ? '?' . http_build_query(request()->except(['tag', 'page'])) : '') }}">Alle tags</a>@endif
    </form>
  </div>
@endif
