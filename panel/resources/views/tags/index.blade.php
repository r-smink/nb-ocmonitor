@extends('layouts.app')
@section('title', 'Tags')
@section('content')
  <div class="dhead"><a class="back" href="{{ route('customers.index') }}" aria-label="Terug">←</a><h2>Tags</h2></div>
  <p class="dsub">Labels voor klanten en sites, bijvoorbeeld "webshop", "hosting bij ons" of "zakelijk". Een tag op een klant geldt voor al zijn sites. Filter er overal op, ook bij de bulk-updates.</p>

  <div class="cols cols-wide">
    <section class="panel">
      <div class="panel-head"><h3>Alle tags</h3><span class="badge">{{ $tags->count() }}</span></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Tag</th><th class="num">Klanten</th><th class="num">Sites (direct)</th>@can('manage-sites')<th></th>@endcan</tr></thead>
          <tbody>
            @forelse ($tags as $tag)
              <tr>
                <td>
                  @can('manage-sites')
                    <form method="POST" action="{{ route('tags.update', $tag) }}" class="row-actions">
                      @csrf
                      @method('PUT')
                      <input name="name" value="{{ $tag->name }}" maxlength="40" class="w-auto" aria-label="Naam">
                      <select name="color" class="w-auto" aria-label="Kleur">
                        @foreach (\App\Models\Tag::COLORS as $color => $label)<option value="{{ $color }}" @selected($tag->color === $color)>{{ $label }}</option>@endforeach
                      </select>
                      <span class="tag {{ $tag->color }}">{{ $tag->name }}</span>
                      <button class="btn ghost small" type="submit">Opslaan</button>
                    </form>
                  @else
                    <span class="tag {{ $tag->color }}">{{ $tag->name }}</span>
                  @endcan
                </td>
                <td class="num">{{ $tag->customers_count }}</td>
                <td class="num"><a href="{{ route('sites.index', ['tag' => $tag->id]) }}">{{ $tag->sites_count }}</a></td>
                @can('manage-sites')
                  <td class="num">
                    <form method="POST" action="{{ route('tags.destroy', $tag) }}" class="inline" data-confirm="Tag {{ $tag->name }} verwijderen? Hij verdwijnt bij alle klanten en sites.">
                      @csrf
                      @method('DELETE')
                      <button class="btn ghost small" type="submit">Verwijderen</button>
                    </form>
                  </td>
                @endcan
              </tr>
            @empty
              <tr><td colspan="4" class="muted">Nog geen tags.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    @can('manage-sites')
      <section class="panel">
        <h3>Tag toevoegen</h3>
        <form method="POST" action="{{ route('tags.store') }}" class="form mt">
          @csrf
          <label>Naam<input name="name" maxlength="40" required placeholder="webshop"></label>
          <label>Kleur
            <select name="color">
              @foreach (\App\Models\Tag::COLORS as $color => $label)<option value="{{ $color }}">{{ $label }}</option>@endforeach
            </select>
          </label>
          <div class="actions"><button class="btn" type="submit">Toevoegen</button></div>
        </form>
      </section>
    @endcan
  </div>
@endsection
