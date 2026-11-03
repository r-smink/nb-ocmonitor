@extends('layouts.app')
@section('title', 'Licenties')
@section('content')
  @php($canEdit = auth()->user()->can('manage-sites'))
  <div class="page-head">
    <div>
      <h1>Licenties</h1>
      <p>Betaalde plugins krijgen alleen updates als de licentie op de site actief is. Hier staan jullie sleutels versleuteld, met per site de status.</p>
    </div>
  </div>

  @foreach ($products as ['product' => $product, 'installations' => $installations])
    @php($inactive = $installations->filter(fn ($i) => $i['status'] === false)->count())
    <section class="panel">
      <div class="panel-head">
        <h3>{{ $product->name }}</h3>
        <span class="mono muted">{{ $product->plugin_file }}</span>
        <span class="badge">{{ $installations->count() }} site(s)</span>
        @if ($inactive > 0)<span class="badge sec">{{ $inactive }} zonder actieve licentie</span>@endif
        <span class="hint">{{ \App\Models\LicenseProduct::METHODS[$product->method] ?? $product->method }}{{ $product->method === 'option' ? ' (' . $product->option_name . ')' : '' }}</span>
        @if ($canEdit && $product->automatic() && $product->licenses->isNotEmpty() && $installations->isNotEmpty())
          <div class="right">
            <form method="POST" action="{{ route('licenses.activate', $product) }}" class="row-actions" data-confirm="{{ $product->name }} activeren op alle sites zonder actieve licentie?">
              @csrf
              @if ($product->licenses->count() > 1)
                <select name="license" class="w-auto" aria-label="Licentie">
                  @foreach ($product->licenses as $license)<option value="{{ $license->id }}">{{ $license->label }}</option>@endforeach
                </select>
              @else
                <input type="hidden" name="license" value="{{ $product->licenses->first()->id }}">
              @endif
              <button class="btn small" type="submit">{{ $inactive > 0 ? 'Activeren op ' . $inactive . ' site(s)' : 'Activeren op alle sites zonder licentie' }}</button>
            </form>
          </div>
        @endif
      </div>

      <div class="cols cols-wide">
        <div>
          <h4>Sites</h4>
          <div class="mini-list scroll-sm">
            @forelse ($installations as $i)
              <div class="mini-row">
                <div class="grow"><a class="name" href="{{ route('sites.show', $i['site']) }}">{{ $i['site']->name }}</a>@unless ($i['active']) <span class="sub">(inactief)</span>@endunless</div>
                <span class="ver">{{ $i['version'] }}</span>
                @if (in_array($i['site']->id . ':' . $product->method, $open, true))
                  <span class="state idle">wordt geactiveerd</span>
                @elseif ($i['status'] === true)
                  <span class="state">actief</span>
                @elseif ($i['status'] === false)
                  <span class="state bad">niet actief</span>
                @else
                  <span class="state idle">onbekend</span>
                @endif
                @if ($canEdit)
                  @if ($product->automatic() && $product->licenses->isNotEmpty() && $i['status'] !== true && !in_array($i['site']->id . ':' . $product->method, $open, true))
                    <form method="POST" action="{{ route('licenses.activate.site', [$product, $i['site']]) }}" class="row-actions">
                      @csrf
                      @if ($product->licenses->count() > 1)
                        <select name="license" class="w-auto" aria-label="Licentie">
                          @foreach ($product->licenses as $license)<option value="{{ $license->id }}">{{ $license->label }}</option>@endforeach
                        </select>
                      @else
                        <input type="hidden" name="license" value="{{ $product->licenses->first()->id }}">
                      @endif
                      <button class="btn small quiet" type="submit">Activeren</button>
                    </form>
                  @elseif (!$product->automatic() && $product->settings_path)
                    <a class="btn small quiet" href="{{ rtrim($i['site']->url, '/') }}/wp-admin/{{ $product->settings_path }}" target="_blank" rel="noopener noreferrer">Openen in WP-admin</a>
                  @endif
                @endif
              </div>
            @empty
              <p class="muted">Op geen enkele gekoppelde site gevonden (volgens de laatste inventaris).</p>
            @endforelse
          </div>
          @if ($product->method === 'manual')
            <p class="note mt">{{ $product->status_key === 'rank_math_pro' ? 'Rank Math Pro werkt niet met sleutels: koppel de site met jullie Rank Math-account (Rank Math → Dashboard → Account). De status hierboven komt van de site zelf.' : 'Dit product activeer je handmatig: kopieer de sleutel en plak hem in de instellingen van de plugin.' }}</p>
          @elseif (!$product->status_key)
            <p class="note mt">De site kan voor dit product niet melden of de licentie actief is; controleer het in WP-admin.</p>
          @endif
        </div>

        <div>
          <h4>Sleutels</h4>
          <div class="key-list">
            @forelse ($product->licenses as $license)
              <div class="key-card">
                <div class="key-card-head">
                  <b>{{ $license->label }}</b>
                  @if ($canEdit)
                    <form method="POST" action="{{ route('licenses.destroy', $license) }}" class="row-form" data-confirm="Licentie {{ $license->label }} uit het panel verwijderen?">
                      @csrf
                      @method('DELETE')
                      <button class="btn ghost small" type="submit">Verwijderen</button>
                    </form>
                  @endif
                </div>
                <div class="mono">
                  @if ($canEdit)
                    <details><summary>{{ $license->masked() }}</summary><code id="lic-{{ $license->id }}">{{ $license->key }}</code> <button class="btn ghost small" type="button" data-copy="#lic-{{ $license->id }}">Kopieer</button></details>
                  @else
                    {{ $license->masked() }}
                  @endif
                </div>
                <div class="sub">{{ $license->seats ?? '∞' }} sites · <span @class(['issue-text', 'warn' => $license->expires_on && $license->expires_on->lt(now()->addDays(30))])>{{ $license->expires_on ? 'verloopt ' . $license->expires_on->format('d-m-Y') : 'verloopt niet' }}</span></div>
                @if ($license->notes)<div class="sub">{{ $license->notes }}</div>@endif
              </div>
            @empty
              <p class="muted">Nog geen sleutel.</p>
            @endforelse
          </div>
          @if ($canEdit)
            <form method="POST" action="{{ route('licenses.store', $product) }}" class="form mt">
              @csrf
              <div class="form-row">
                <label>Naam<input name="label" maxlength="120" required placeholder="Agency-licentie"></label>
                <label>Sleutel<input name="key" maxlength="200" required autocomplete="off" spellcheck="false"></label>
              </div>
              <div class="form-row">
                <label>Aantal sites (optioneel)<input type="number" name="seats" min="1"></label>
                <label>Verloopt op (optioneel)<input type="date" name="expires_on"></label>
              </div>
              <div class="actions"><button class="btn ghost small" type="submit">Sleutel toevoegen</button></div>
            </form>
            <details class="mt">
              <summary class="hint">Product bewerken of verwijderen</summary>
              <form method="POST" action="{{ route('licenses.products.update', $product) }}" class="form mt">
                @csrf
                @method('PUT')
                @include('licenses.product-fields', ['product' => $product])
                <div class="actions"><button class="btn ghost small" type="submit">Opslaan</button></div>
              </form>
              <form method="POST" action="{{ route('licenses.products.destroy', $product) }}" class="mt" data-confirm="{{ $product->name }} en alle sleutels verwijderen?">
                @csrf
                @method('DELETE')
                <button class="btn danger small" type="submit">Product verwijderen</button>
              </form>
            </details>
          @endif
        </div>
      </div>
    </section>
  @endforeach

  @if ($canEdit)
    <section class="panel narrow">
      <h3>Product toevoegen</h3>
      <form method="POST" action="{{ route('licenses.products.store') }}" class="form mt">
        @csrf
        <label>Snel kiezen
          <select name="preset">
            <option value="">Zelf invullen…</option>
            @foreach ($presets as $key => $preset)<option value="{{ $key }}">{{ $preset['name'] }}</option>@endforeach
          </select>
        </label>
        <p class="note">Kies je een bekend product, dan vult het panel de rest in. Je kunt de velden hieronder leeg laten.</p>
        @include('licenses.product-fields', ['product' => null])
        <div class="actions"><button class="btn" type="submit">Toevoegen</button></div>
      </form>
    </section>
  @endif
@endsection
