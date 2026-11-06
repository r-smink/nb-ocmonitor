@extends('layouts.app')
@section('title', $schedule->exists ? 'Schema bewerken' : 'Schema toevoegen')
@section('content')
  <div class="page-head">
    <div>
      <h1>{{ $schedule->exists ? 'Schema bewerken' : 'Schema toevoegen' }}</h1>
      <p>Tijden in Nederlandse tijd. Mist de scheduler het moment, dan wordt het tot {{ \App\Models\UpdateSchedule::CATCH_UP_HOURS }} uur erna nog ingehaald; anders volgt de volgende geplande dag.</p>
    </div>
  </div>
  @include('updates.tabs')

  @php($ids = $schedule->scope_ids ?? [])
  <form method="POST" action="{{ $schedule->exists ? route('updates.schedules.update', $schedule) : route('updates.schedules.store') }}" class="form">
    @csrf
    @if ($schedule->exists) @method('PUT') @endif
    <div class="cols">
      <section class="panel">
        <h3>Wanneer</h3>
        <div class="stack mt">
          <label>Naam<input name="name" value="{{ old('name', $schedule->name) }}" maxlength="80" required placeholder="bijv. Plugins op dinsdagochtend"></label>
          <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $schedule->enabled))> Schema staat aan</label>
          <div>
            <span class="field-label">Dagen</span>
            <div class="day-grid">
              @foreach (\App\Models\UpdateSchedule::DAYS as $n => $label)
                <label class="check"><input type="checkbox" name="days[]" value="{{ $n }}" @checked(in_array($n, array_map('intval', old('days', $schedule->days ?? [])), true))> {{ $label }}</label>
              @endforeach
            </div>
          </div>
          <div class="form-row">
            <label>Tijd<input type="time" name="time" value="{{ old('time', $schedule->time) }}" required></label>
            <label>Pauze tot en met<input type="date" name="paused_until" value="{{ old('paused_until', $schedule->paused_until?->toDateString()) }}"></label>
          </div>
          <p class="note">Pauze: bijvoorbeeld in december of tijdens een campagne. Leeg = geen pauze.</p>
        </div>
      </section>

      <section class="panel">
        <h3>Wat</h3>
        <div class="stack mt">
          <div class="check-grid">
            @foreach (\App\Models\UpdateSchedule::TYPES as $key => $label)
              <label><input type="checkbox" name="types[]" value="{{ $key }}" @checked(in_array($key, old('types', $schedule->types ?? []), true))> {{ $label }}</label>
            @endforeach
          </div>
          <p class="note">WordPress zelf heeft geen automatische rollback per component; alleen de backup vooraf.</p>
          <label class="check"><input type="checkbox" name="security_only" value="1" @checked(old('security_only', $schedule->security_only))> Alleen updates die een bekende kwetsbaarheid oplossen</label>
          @php($mode = old('only_mode', !empty($schedule->include) ? 'selected' : 'all'))
          @php($chosen = array_map('strtolower', old('include', $schedule->include ?? [])))
          <fieldset class="stack">
            <legend class="hint">Welke plugins en thema's</legend>
            <label class="check"><input type="radio" name="only_mode" value="all" @checked($mode === 'all')> Alle plugins en thema's (behalve wat hieronder bij "nooit" staat)</label>
            <label class="check"><input type="radio" name="only_mode" value="selected" @checked($mode === 'selected')> Alleen deze plugins en thema's:</label>
            @foreach (['plugin' => 'Plugins', 'theme' => 'Thema\'s'] as $type => $label)
              @if (!empty($components[$type]))
                <details @if ($mode === 'selected') open @endif>
                  <summary>{{ $label }} ({{ count($components[$type]) }})</summary>
                  <div class="check-grid mt">
                    @foreach ($components[$type] as $slug => $c)
                      <label title="{{ $slug }}"><input type="checkbox" name="include[]" value="{{ $slug }}" @checked(in_array($slug, $chosen, true))> {{ $c['name'] }} <span class="muted">({{ $c['sites'] }})</span></label>
                    @endforeach
                  </div>
                </details>
              @endif
            @endforeach
            <p class="note">WordPress zelf, vertalingen en de OC-plugin volgen de soorten hierboven, niet deze keuze.</p>
          </fieldset>
          <label>Nooit automatisch (slugs, één per regel of met komma's)
            <textarea name="exclude" rows="3" placeholder="elementor-pro, woocommerce">{{ old('exclude', implode("\n", $schedule->exclude ?? [])) }}</textarea>
          </label>
          <label class="check"><input type="checkbox" name="rollback_on_visual" value="1" @checked(old('rollback_on_visual', $schedule->rollback_on_visual))> Bestanden automatisch terugzetten als de homepage na de updates zichtbaar veranderd is</label>
          <p class="note">Zet alleen de bestanden uit de backup terug, nooit de database. @unless ($backups && $visual) Werkt pas als backups en de visuele controle aan staan. @endunless</p>
        </div>
      </section>
    </div>

    <section class="panel">
      <h3>Voor welke sites</h3>
      <div class="stack mt">
        <div class="day-grid">
          @foreach (\App\Models\UpdateSchedule::SCOPES as $key => $label)
            <label class="check"><input type="radio" name="scope" value="{{ $key }}" @checked(old('scope', $schedule->scope) === $key)> {{ $label }}</label>
          @endforeach
        </div>
        @if ($tags->isNotEmpty())
          <div><span class="field-label">Tags</span><div class="check-grid">
            @foreach ($tags as $tag)<label><input type="checkbox" name="scope_tags[]" value="{{ $tag->id }}" @checked($schedule->scope === 'tags' && in_array($tag->id, $ids, true))> <span class="tag {{ $tag->color }}">{{ $tag->name }}</span></label>@endforeach
          </div></div>
        @endif
        @if ($customers->isNotEmpty())
          <div><span class="field-label">Klanten</span><div class="check-grid">
            @foreach ($customers as $customer)<label><input type="checkbox" name="scope_customers[]" value="{{ $customer->id }}" @checked($schedule->scope === 'customers' && in_array($customer->id, $ids, true))> {{ $customer->name }}</label>@endforeach
          </div></div>
        @endif
        <div><span class="field-label">Losse sites</span><div class="check-grid">
          @foreach ($sites as $site)<label><input type="checkbox" name="scope_sites[]" value="{{ $site->id }}" @checked($schedule->scope === 'sites' && in_array($site->id, $ids, true))> {{ $site->name }}</label>@endforeach
        </div></div>
        <p class="note">Alleen de lijst die bij je keuze hierboven hoort telt.</p>
      </div>
    </section>

    <div class="actions">
      <button class="btn" type="submit">Opslaan</button>
      <a class="btn ghost" href="{{ route('updates.schedules.index') }}">Annuleren</a>
    </div>
  </form>

  @if ($schedule->exists)
    <form method="POST" action="{{ route('updates.schedules.destroy', $schedule) }}" class="mt" data-confirm="Schema {{ $schedule->name }} verwijderen?">
      @csrf
      @method('DELETE')
      <button class="btn danger small" type="submit">Schema verwijderen</button>
    </form>
  @endif
@endsection
