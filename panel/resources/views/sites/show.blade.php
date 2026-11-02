@extends('layouts.app')
@section('title', $site->name)
@if ($jobs->whereIn('status', ['pending', 'running'])->isNotEmpty())
  @section('refresh', '5')
@endif
@section('content')
  @php($level = $site->level())
  <div class="lvl-{{ $level }}">
    <div class="dhead">
      <a class="back" href="{{ route('sites.index') }}" aria-label="Terug">←</a>
      <span class="dot"></span>
      <h2>{{ $site->name }}</h2>
      <span class="lvl-label">{{ ['ok' => 'In orde', 'warn' => 'Aandacht', 'crit' => 'Kritiek', 'pending' => 'Niet gekoppeld'][$level] }}</span>
      @if ($site->client_name)<span class="badge">{{ $site->client_name }}</span>@endif
    </div>
    <p class="dsub"><a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer">{{ $site->url }}</a> · {{ $site->site_id }}</p>
  </div>

  @php($issues = $site->issues())
  @if ($issues !== [] && $site->isPaired())
    <div @class(['alert', 'warn' => $level !== 'crit'])>
      <ul class="issues">
        @foreach ($issues as $issue)<li class="{{ $issue['level'] }}">{{ $issue['text'] }}</li>@endforeach
      </ul>
      @if ($site->last_error)<p class="note mt">Laatste fout: {{ $site->last_error }}</p>@endif
    </div>
  @endif

  @if (!$site->isPaired())
    <div class="panel">
      <div class="panel-head"><h3>Koppelen</h3></div>
      <ol class="steps">
        <li><a href="{{ route('plugin.download') }}">Download de client-plugin</a>{{ $pluginVersion ? ' (v' . $pluginVersion . ')' : '' }} en installeer en activeer hem op {{ $site->host }}.</li>
        <li>Ga op de site naar <b>Extra → NB OC Monitor</b> en plak de koppelcode hieronder, of gebruik <code>wp nb-ocm pair &lt;code&gt;</code>.</li>
      </ol>
      @if ($pairingCode)
        <p class="note mt">Geldig tot {{ $site->pairing_expires_at->timezone('Europe/Amsterdam')->format('H:i') }}. Eenmalig te gebruiken, en alleen door {{ $site->host }}.</p>
        <textarea id="pairCode" class="pair-code" readonly rows="4">{{ $pairingCode }}</textarea>
        <div class="row-actions mt">
          <button class="btn small" type="button" data-copy="#pairCode">Kopieer koppelcode</button>
          <a class="btn ghost small" href="{{ route('sites.show', $site) }}">Status verversen</a>
        </div>
      @else
        <p class="note mt">De koppelcode is verlopen of al gebruikt.</p>
      @endif
      @can('manage-sites')
        <form method="POST" action="{{ route('sites.pairing', $site) }}" class="mt">
          @csrf
          <button class="btn ghost small" type="submit">Nieuwe koppelcode maken</button>
        </form>
      @endcan
    </div>
  @endif

  <div class="cols">
    <div class="panel">
      <div class="panel-head">
        <h3>Status</h3>
        @can('manage-sites')
          @if ($site->isPaired())
            <div class="right">
              @foreach ($commands as $command => $label)
                <form method="POST" action="{{ route('sites.command', $site) }}" class="inline">
                  @csrf
                  <input type="hidden" name="command" value="{{ $command }}">
                  <button class="btn ghost small" type="submit">{{ $label }} nu</button>
                </form>
              @endforeach
            </div>
          @endif
        @endcan
      </div>
      <dl class="kv">
        <dt>WordPress</dt><dd>{{ $site->wp_version ?? '–' }}</dd>
        <dt>PHP</dt><dd>{{ $site->php_version ?? '–' }}</dd>
        <dt>Plugin</dt><dd>{{ $site->plugin_version ?? '–' }}</dd>
        <dt>Laatst gezien</dt><dd>{{ $site->last_seen_at?->timezone('Europe/Amsterdam')->format('d-m-Y H:i') ?? '–' }}</dd>
        <dt>Inventaris</dt><dd>{{ $site->inventory_at?->timezone('Europe/Amsterdam')->format('d-m-Y H:i') ?? '–' }}</dd>
        <dt>Gekoppeld</dt><dd>{{ $site->paired_at?->timezone('Europe/Amsterdam')->format('d-m-Y H:i') ?? 'nee' }}</dd>
        <dt>Site-sleutel</dt><dd class="mono">{{ $site->key_id ?? '–' }}</dd>
        <dt>Scopes</dt><dd>{{ implode(', ', $site->scopes ?? []) }}</dd>
        <dt>Laatste event</dt>
        <dd>#{{ $site->last_event_seq }}
          @if ($site->event_gap_at)
            <span class="badge sec">gat sinds {{ $site->event_gap_at->timezone('Europe/Amsterdam')->format('d-m H:i') }}</span>
          @endif
        </dd>
      </dl>
    </div>

    <div class="panel">
      <h3>Opdrachten</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Tijd</th><th>Command</th><th>Status</th><th>Door</th></tr></thead>
          <tbody>
            @forelse ($jobs as $job)
              <tr>
                <td>{{ $job->created_at->timezone('Europe/Amsterdam')->format('d-m H:i:s') }}</td>
                <td class="mono">{{ $job->command }}</td>
                <td class="wrap">
                  <span @class(['state', 'bad' => in_array($job->status, ['failed', 'rejected']), 'idle' => in_array($job->status, ['pending', 'running'])])>{{ $job->status }}</span>
                  @if ($job->error)<div class="note">{{ \Illuminate\Support\Str::limit($job->error, 160) }}</div>@endif
                </td>
                <td>{{ $job->requester?->name ?? 'systeem' }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="muted">Nog geen opdrachten.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  @if ($inventory !== [])
    @php($plugins = collect($inventory['plugins'] ?? [])->sortBy(fn ($p) => (empty($p['update']) ? '1' : '0') . strtolower($p['name'] ?? '')))
    <div class="panel">
      <div class="panel-head">
        <h3>Plugins</h3><span class="badge">{{ $plugins->count() }}</span>
        @if (!empty($inventory['core']['update']))
          <span class="badge sec">WordPress {{ $inventory['core']['update']['version'] }} beschikbaar</span>
        @endif
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Plugin</th><th>Versie</th><th>Update</th><th>Actief</th><th>Auto-update</th></tr></thead>
          <tbody>
            @foreach ($plugins as $plugin)
              <tr>
                <td>{{ $plugin['name'] ?? $plugin['file'] }} <span class="muted mono">{{ $plugin['slug'] ?? '' }}</span></td>
                <td class="mono">{{ $plugin['version'] ?? '' }}</td>
                <td @class(['lvl-warn' => !empty($plugin['update'])])>{{ $plugin['update']['version'] ?? '' }}</td>
                <td>{{ !empty($plugin['active']) ? 'ja' : 'nee' }}</td>
                <td>{{ !empty($plugin['auto_update']) ? 'aan' : 'uit' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3>Thema's</h3><span class="badge">{{ count($inventory['themes'] ?? []) }}</span></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Thema</th><th>Versie</th><th>Update</th><th>Actief</th></tr></thead>
          <tbody>
            @foreach ($inventory['themes'] ?? [] as $theme)
              <tr>
                <td>{{ $theme['name'] ?? $theme['stylesheet'] }}</td>
                <td class="mono">{{ $theme['version'] ?? '' }}</td>
                <td @class(['lvl-warn' => !empty($theme['update'])])>{{ $theme['update']['version'] ?? '' }}</td>
                <td>{{ !empty($theme['active']) ? 'ja' : '' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif

  <div class="panel">
    <div class="panel-head"><h3>Wijzigingen op de site</h3><span class="hint">Alles wat er op de site veranderde, ook buiten het panel om.</span></div>
    @include('partials.events-table', ['events' => $events, 'showSite' => false])
  </div>

  @can('manage-sites')
    <div class="panel narrow">
      <h3>Site verwijderen</h3>
      <p class="note">Verwijdert de site en alle gegevens uit het panel. Ontkoppel daarna ook de plugin op de site zelf.</p>
      <form method="POST" action="{{ route('sites.destroy', $site) }}" class="form mt" data-confirm="Site {{ $site->name }} definitief verwijderen?">
        @csrf
        @method('DELETE')
        <label>Typ <b>{{ $site->host }}</b> ter bevestiging<input name="confirm" autocomplete="off" required></label>
        <div class="actions"><button class="btn danger" type="submit">Verwijderen</button></div>
      </form>
    </div>
  @endcan
@endsection
