@extends('layouts.app')
@section('title', 'Instellingen')
@section('content')
  <div class="page-head">
    <div>
      <h1>Instellingen</h1>
      <p>Algemene instellingen van OC Monitor. Laat je een veld leeg, dan geldt de waarde uit <code>.env</code> (tussen haakjes). Sleutels van de signer, de database en Spaces staan bewust alleen in <code>.env</code>.</p>
    </div>
  </div>

  @php($field = fn (string $key) => \App\Ocm\AppSettings::FIELDS[$key])
  <form method="POST" action="{{ route('settings.update') }}" class="form">
    @csrf
    @method('PUT')
    <div class="cols cols-wide">
      <section class="panel">
        <div class="panel-head"><h3>Menu</h3><span class="hint">Verborgen: niet in het menu, wel via een link. Uit: het onderdeel is helemaal weg, ook op de sitepagina.</span></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Onderdeel</th>@foreach (\App\Ocm\Menu::STATES as $label)<th class="num">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
              @foreach (\App\Ocm\Menu::ITEMS as $key => [$label, , , $fixed])
                <tr>
                  <td>{{ $label }}@if ($fixed) <span class="muted">(altijd aan)</span>@endif</td>
                  @foreach (array_keys(\App\Ocm\Menu::STATES) as $state)
                    <td class="num"><input type="radio" name="menu[{{ $key }}]" value="{{ $state }}" aria-label="{{ $label }}: {{ \App\Ocm\Menu::STATES[$state] }}" @checked(\App\Ocm\Menu::state($key) === $state) @disabled($fixed)></td>
                  @endforeach
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h3>Status</h3></div>
        <dl class="kv">
          @foreach ($status as $label => $ok)
            <dt>{{ $label }}</dt><dd><span @class(['state', 'idle' => !$ok])>{{ $ok ? 'ingesteld' : 'niet ingesteld' }}</span></dd>
          @endforeach
          <dt>Plugin-release</dt><dd class="mono">{{ $release ?? '–' }}</dd>
          <dt>Kwetsbaarheden-feed</dt><dd>{{ !empty($feed['synced_at']) ? \Carbon\Carbon::parse($feed['synced_at'])->diffForHumans() : 'nog niet opgehaald' }}</dd>
        </dl>
      </section>
    </div>

    <div class="cols">
      <section class="panel">
        <h3>Algemeen en monitoring</h3>
        <div class="stack mt">
          @foreach (['app_name', 'heartbeat_minutes', 'ssl_warn_days', 'domain_warn_days'] as $key)
            @include('settings.field', ['key' => $key])
          @endforeach
        </div>
      </section>
      <section class="panel">
        <h3>Updates en backups</h3>
        <div class="stack mt">
          @foreach (['update_chunk', 'backups_enabled', 'backup_keep_days', 'visual_enabled', 'visual_threshold'] as $key)
            @include('settings.field', ['key' => $key])
          @endforeach
        </div>
      </section>
    </div>

    <section class="panel">
      <h3>PageSpeed en kwetsbaarheden</h3>
      <div class="form-row mt">
        @foreach (['pagespeed_api_key', 'pagespeed_interval_days', 'pagespeed_drop', 'wordfence_api_key'] as $key)
          @include('settings.field', ['key' => $key])
        @endforeach
      </div>
    </section>

    <div class="actions"><button class="btn" type="submit">Instellingen opslaan</button></div>
  </form>
@endsection
