@extends('layouts.app')
@section('title', $customer->exists ? $customer->name . ' bewerken' : 'Klant toevoegen')
@section('content')
  @php($sections = old('report_sections', $customer->exists ? $customer->sections() : \App\Models\Customer::DEFAULT_SECTIONS))
  @php($siteIds = array_map('intval', old('sites', $customer->exists ? $customer->sites->pluck('id')->all() : [])))
  <div class="dhead">
    <a class="back" href="{{ $customer->exists ? route('customers.show', $customer) : route('customers.index') }}" aria-label="Terug">←</a>
    <h2>{{ $customer->exists ? $customer->name . ' bewerken' : 'Klant toevoegen' }}</h2>
  </div>

  <form class="form" method="POST" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}">
    @csrf
    @if ($customer->exists) @method('PUT') @endif
    <div class="cols">
      <div class="panel">
        <h3>Gegevens</h3>
        <div class="stack">
          <label>Naam klant of bedrijf<input name="name" value="{{ old('name', $customer->name) }}" required maxlength="120" @if (!$customer->exists) autofocus @endif></label>
          <div class="form-row">
            <label>Contactpersoon<input name="contact_name" value="{{ old('contact_name', $customer->contact_name) }}" maxlength="120"></label>
            <label>Telefoon<input name="phone" value="{{ old('phone', $customer->phone) }}" maxlength="40"></label>
          </div>
          <label>E-mail (ontvangt het rapport)<input type="email" name="email" value="{{ old('email', $customer->email) }}" required maxlength="190"></label>
          <label>Ook in cc (optioneel, gescheiden door komma's)<input name="report_cc" value="{{ old('report_cc', $customer->report_cc) }}" maxlength="255" placeholder="marketing@klant.nl, eigenaar@klant.nl"></label>
          <label>Notities (intern)<textarea name="notes" rows="3" maxlength="5000">{{ old('notes', $customer->notes) }}</textarea></label>
        </div>
      </div>

      <div class="panel">
        <h3>Onderhoudscontract</h3>
        <div class="stack">
          <div class="form-row">
            <label>Frequentie
              <select name="visits_per_year">
                @foreach (\App\Models\Customer::VISITS as $value => $label)
                  <option value="{{ $value }}" @selected((int) old('visits_per_year', $customer->visits_per_year) === $value)>{{ $label }}</option>
                @endforeach
              </select>
            </label>
            <label>Startdatum (eerste beurt)<input type="date" name="contract_start" value="{{ old('contract_start', $customer->contract_start?->format('Y-m-d')) }}"></label>
          </div>
          <p class="note">De volgende beurten volgen vanaf de startdatum: bij 4x per jaar elke 3 maanden, bij elke maand op dezelfde dag van de maand.</p>

          <h4 class="mt">Websites</h4>
          @if ($sites->isEmpty())
            <p class="muted">Er zijn geen sites zonder klant. <a href="{{ route('sites.create') }}">Site toevoegen</a></p>
          @else
            <div class="check-grid">
              @foreach ($sites as $site)
                <label><input type="checkbox" name="sites[]" value="{{ $site->id }}" @checked(in_array($site->id, $siteIds, true))> {{ $site->name }}</label>
              @endforeach
            </div>
          @endif
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h3>Rapport</h3>
        <span class="hint">De opmaak stel je in onder <a href="{{ route('reports.index') }}">Rapporten</a>.</span>
      </div>
      <label class="check-grid"><span><input type="checkbox" name="report_enabled" value="1" @checked(old('report_enabled', $customer->report_enabled))> Deze klant krijgt een rapport na elke onderhoudsbeurt</span></label>
      <h4 class="mt">Tags</h4>
      @php($tagIds = array_map('intval', old('tags', $customer->exists ? $customer->tags->pluck('id')->all() : [])))
      @php($allTags = \App\Models\Tag::query()->orderBy('name')->get())
      @if ($allTags->isEmpty())
        <p class="muted">Nog geen tags. <a href="{{ route('tags.index') }}">Tags aanmaken</a></p>
      @else
        <div class="check-grid">
          @foreach ($allTags as $tag)<label><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $tagIds, true))> <span class="tag {{ $tag->color }}">{{ $tag->name }}</span></label>@endforeach
        </div>
        <p class="note">Tags van de klant gelden ook voor al zijn websites.</p>
      @endif

      <h4 class="mt">Wat er in het rapport staat</h4>
      <div class="check-grid">
        @foreach (\App\Models\Customer::REPORT_SECTIONS as $key => $label)
          <label><input type="checkbox" name="report_sections[]" value="{{ $key }}" @checked(in_array($key, $sections, true))> {{ $label }}</label>
        @endforeach
      </div>
    </div>

    <div class="actions"><button class="btn" type="submit">{{ $customer->exists ? 'Opslaan' : 'Klant toevoegen' }}</button></div>
  </form>

  @if ($customer->exists)
    <div class="panel narrow mt">
      <h3>Klant verwijderen</h3>
      <p class="note">De sites blijven bestaan; alleen de klant, het contract en de onderhoudshistorie verdwijnen.</p>
      <form method="POST" action="{{ route('customers.destroy', $customer) }}" class="mt" data-confirm="Klant {{ $customer->name }} verwijderen?">
        @csrf
        @method('DELETE')
        <button class="btn danger" type="submit">Verwijderen</button>
      </form>
    </div>
  @endif
@endsection
