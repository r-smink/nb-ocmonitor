@extends('layouts.app')
@section('title', $site->name)
@if ($jobs->whereIn('status', ['pending', 'running'])->isNotEmpty())
  @section('refresh', '5')
@elseif ($site->pagespeed_requested_at)
  @section('refresh', '15')
@endif
@section('content')
  @php($level = $site->level())
  <div class="lvl-{{ $level }}">
    <div class="dhead">
      <a class="back" href="{{ route('sites.index') }}" aria-label="Terug">←</a>
      <span class="dot"></span>
      <h2>{{ $site->name }}</h2>
      <span class="lvl-label">{{ ['ok' => 'In orde', 'warn' => 'Aandacht', 'crit' => 'Kritiek', 'pending' => 'Niet gekoppeld'][$level] }}</span>
      @if ($site->customer)
        <a class="badge" href="{{ route('customers.show', $site->customer) }}">{{ $site->customer->name }}</a>
      @elseif ($site->client_name)
        <span class="badge">{{ $site->client_name }}</span>
      @endif
    </div>
    <p class="dsub"><a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer">{{ $site->url }}</a> · {{ $site->site_id }}</p>
  </div>

  @php($issues = collect($site->issues()))
  @php($urgent = $issues->whereIn('level', ['crit', 'warn']))
  @if ($urgent->isNotEmpty() && $site->isPaired())
    <div @class(['alert', 'warn' => $level !== 'crit'])>
      <ul class="issues">
        @foreach ($urgent as $issue)<li class="{{ $issue['level'] }}"><b>{{ \App\Ocm\SiteIssues::CATEGORIES[$issue['category']] }}:</b> {{ $issue['text'] }}</li>@endforeach
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
      @php($tips = $issues->where('level', 'info'))
      @if ($tips->isNotEmpty() && $site->isPaired())
        <h4 class="mt">Aanbevelingen</h4>
        <ul class="issues">
          @foreach ($tips as $issue)<li class="info">{{ $issue['text'] }}</li>@endforeach
        </ul>
      @endif
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
                <td class="wrap">@include('partials.job-result', ['job' => $job])</td>
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
    @php($canUpdate = auth()->user()->can('manage-sites') && $updatesBlocked === null)
    @php($pluginUpdates = $updates->where('type', 'plugin')->reject(fn ($u) => in_array($u['id'], $queued['plugin'] ?? [], true)))
    @php($themeUpdates = $updates->where('type', 'theme')->reject(fn ($u) => in_array($u['id'], $queued['theme'] ?? [], true)))

    @if ($updatesBlocked && $updates->isNotEmpty())
      <div class="alert warn mt">Bijwerken vanuit het panel kan hier niet: {{ $updatesBlocked }}.</div>
    @endif

    @if (!empty($inventory['core']['update']) || (int) ($inventory['translations_pending'] ?? 0) > 0)
      <div class="panel">
        <div class="panel-head">
          <h3>WordPress</h3>
          <span class="mono">{{ $inventory['core']['version'] ?? '' }}</span>
          @if (!empty($inventory['core']['update']))
            <span class="badge sec">WordPress {{ $inventory['core']['update']['version'] }} beschikbaar</span>
          @endif
          @if ($canUpdate)
            <div class="right">
              @if ((int) ($inventory['translations_pending'] ?? 0) > 0)
                @if (in_array('translations', $queued['translations'] ?? [], true))
                  <span class="state idle">vertalingen in wachtrij</span>
                @else
                  <form method="POST" action="{{ route('sites.updates', $site) }}" class="inline">
                    @csrf
                    <input type="hidden" name="type" value="translations">
                    <button class="btn ghost small" type="submit">Vertalingen bijwerken ({{ $inventory['translations_pending'] }})</button>
                  </form>
                @endif
              @endif
              @if (!empty($inventory['core']['update']))
                @if (in_array('wordpress', $queued['core'] ?? [], true))
                  <span class="state idle">WordPress-update in wachtrij</span>
                @else
                  <form method="POST" action="{{ route('sites.updates', $site) }}" class="inline" data-confirm="WordPress op {{ $site->name }} bijwerken naar {{ $inventory['core']['update']['version'] }}? Voor WordPress zelf is er geen automatische rollback.">
                    @csrf
                    <input type="hidden" name="type" value="core">
                    <button class="btn small" type="submit">WordPress bijwerken naar {{ $inventory['core']['update']['version'] }}</button>
                  </form>
                @endif
              @endif
            </div>
          @endif
        </div>
        <p class="note">Bij een WordPress-update controleert de site na afloop of de homepage nog werkt. Terugzetten gebeurt bij WordPress zelf niet automatisch.</p>
      </div>
    @endif

    @php($plugins = collect($inventory['plugins'] ?? [])->sortBy(fn ($p) => (empty($p['update']) ? '1' : '0') . strtolower($p['name'] ?? '')))
    @php($ago = fn (?\Carbon\CarbonInterface $at) => $at ? '<span title="' . e($at->timezone('Europe/Amsterdam')->format('d-m-Y H:i')) . '">' . e($at->timezone('Europe/Amsterdam')->format('d-m-Y')) . '</span>' : '<span class="muted">–</span>')
    <div class="cols cols-wide">
    <div class="panel">
      <div class="panel-head">
        <h3>Plugins</h3><span class="badge">{{ $plugins->count() }}</span>
        @if ($updates->where('type', 'plugin')->isNotEmpty())<span class="badge sec">{{ $updates->where('type', 'plugin')->count() }} update(s)</span>@endif
        @if ($canUpdate && $pluginUpdates->count() > 1)
          <div class="right">
            <form method="POST" action="{{ route('sites.updates', $site) }}" class="inline" data-confirm="Alle {{ $pluginUpdates->count() }} plugins met een update bijwerken op {{ $site->name }}?">
              @csrf
              <input type="hidden" name="type" value="plugin">
              <input type="hidden" name="all" value="1">
              <button class="btn small" type="submit">Alle plugins bijwerken ({{ $pluginUpdates->count() }})</button>
            </form>
          </div>
        @endif
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Plugin</th><th>Versie</th><th>Update</th><th>Actief</th><th>Laatst bijgewerkt</th><th></th></tr></thead>
          <tbody>
            @foreach ($plugins as $plugin)
              <tr>
                <td class="wrap">{{ $plugin['name'] ?? $plugin['file'] }}@if (!empty($plugin['auto_update'])) <span class="badge" title="Auto-update staat aan op de site">auto</span>@endif</td>
                <td class="mono">{{ $plugin['version'] ?? '' }}</td>
                <td @class(['lvl-warn' => !empty($plugin['update'])])>{{ $plugin['update']['version'] ?? '' }}</td>
                <td>{{ !empty($plugin['active']) ? 'ja' : 'nee' }}</td>
                <td>{!! $ago($lastUpdated['plugin:' . ($plugin['file'] ?? '')] ?? null) !!}</td>
                <td class="num">
                  @if (!empty($plugin['update']['version']) && isset($plugin['file']))
                    @if (in_array($plugin['file'], $queued['plugin'] ?? [], true))
                      <span class="state idle">in wachtrij</span>
                    @elseif ($canUpdate)
                      <form method="POST" action="{{ route('sites.updates', $site) }}" class="inline">
                        @csrf
                        <input type="hidden" name="type" value="plugin">
                        <input type="hidden" name="ids[]" value="{{ $plugin['file'] }}">
                        <button class="btn ghost small" type="submit">Bijwerken</button>
                      </form>
                    @endif
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head">
        <h3>Thema's</h3><span class="badge">{{ count($inventory['themes'] ?? []) }}</span>
        @if ($updates->where('type', 'theme')->isNotEmpty())<span class="badge sec">{{ $updates->where('type', 'theme')->count() }} update(s)</span>@endif
        @if ($canUpdate && $themeUpdates->count() > 1)
          <div class="right">
            <form method="POST" action="{{ route('sites.updates', $site) }}" class="inline" data-confirm="Alle {{ $themeUpdates->count() }} thema's met een update bijwerken op {{ $site->name }}?">
              @csrf
              <input type="hidden" name="type" value="theme">
              <input type="hidden" name="all" value="1">
              <button class="btn small" type="submit">Alle thema's bijwerken ({{ $themeUpdates->count() }})</button>
            </form>
          </div>
        @endif
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Thema</th><th>Versie</th><th>Update</th><th>Actief</th><th>Laatst bijgewerkt</th><th></th></tr></thead>
          <tbody>
            @foreach ($inventory['themes'] ?? [] as $theme)
              <tr>
                <td>{{ $theme['name'] ?? $theme['stylesheet'] }}</td>
                <td class="mono">{{ $theme['version'] ?? '' }}</td>
                <td @class(['lvl-warn' => !empty($theme['update'])])>{{ $theme['update']['version'] ?? '' }}</td>
                <td>{{ !empty($theme['active']) ? 'ja' : '' }}</td>
                <td>{!! $ago($lastUpdated['theme:' . ($theme['stylesheet'] ?? '')] ?? null) !!}</td>
                <td class="num">
                  @if (!empty($theme['update']['version']) && isset($theme['stylesheet']))
                    @if (in_array($theme['stylesheet'], $queued['theme'] ?? [], true))
                      <span class="state idle">in wachtrij</span>
                    @elseif ($canUpdate)
                      <form method="POST" action="{{ route('sites.updates', $site) }}" class="inline">
                        @csrf
                        <input type="hidden" name="type" value="theme">
                        <input type="hidden" name="ids[]" value="{{ $theme['stylesheet'] }}">
                        <button class="btn ghost small" type="submit">Bijwerken</button>
                      </form>
                    @endif
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    </div>
    @if ($updates->where('type', 'theme')->isEmpty() && collect($inventory['themes'] ?? [])->isNotEmpty())
      <p class="note">Thema's worden bijgewerkt zodra WordPress op de site een update aanbiedt. Premium-thema's met een eigen updater (bijv. via een licentie) verschijnen hier pas als die updater de update aan WordPress doorgeeft.</p>
    @endif
  @endif

  @if ($site->isPaired())
    <div class="panel">
      <div class="panel-head">
        <h3>PageSpeed</h3>
        @if ($pagespeed->isNotEmpty())<span class="hint">gemeten {{ $pagespeed->first()->measured_at->diffForHumans() }}</span>@endif
        @can('manage-sites')
          <div class="right">
            @if ($site->pagespeed_requested_at)
              <span class="spinner" role="status" title="Meting loopt (duurt meestal 1 tot 5 minuten)"><span class="sr-only">Meting loopt</span></span>
            @else
              <form method="POST" action="{{ route('sites.pagespeed', $site) }}" class="inline">
                @csrf
                <button class="btn ghost small" type="submit">Nu meten</button>
              </form>
            @endif
          </div>
        @endcan
      </div>
      @if ($pagespeedLastError)
        <p class="note">Laatste meting mislukt: {{ $pagespeedLastError->error }}</p>
      @endif
      @if ($pagespeed->isEmpty())
        <p class="muted">Nog niet gemeten. De eerste meting volgt automatisch, of kies "Nu meten".</p>
      @else
        <div class="psi-grid">
          @foreach (\App\Models\PagespeedResult::STRATEGIES as $strategy => $label)
            @php($r = $pagespeed->get($strategy))
            <div>
              <div class="psi-head">{{ $label }}</div>
              @if ($r)
                <div class="psi-cats">
                  @foreach (['performance' => 'Prestaties', 'accessibility' => 'Toegankelijkheid', 'best_practices' => 'Best practices', 'seo' => 'SEO'] as $field => $name)
                    <div class="psi-cat">@include('partials.score', ['value' => $r->{$field}, 'class' => 'big']){{ $name }}</div>
                  @endforeach
                </div>
                <dl class="kv">
                  <dt>LCP</dt><dd>{{ $r->lcp_ms !== null ? number_format($r->lcp_ms / 1000, 1, ',', '') . ' s' : '–' }}@if (isset($r->field['lcp_ms'])) <span class="muted">· bezoekers {{ number_format($r->field['lcp_ms'] / 1000, 1, ',', '') }} s</span>@endif</dd>
                  <dt>CLS</dt><dd>{{ $r->cls !== null ? number_format($r->cls, 3, ',', '') : '–' }}@if (isset($r->field['cls'])) <span class="muted">· bezoekers {{ number_format($r->field['cls'], 2, ',', '') }}</span>@endif</dd>
                  <dt>INP (bezoekers)</dt><dd>{{ isset($r->field['inp_ms']) ? $r->field['inp_ms'] . ' ms' : 'geen velddata' }}</dd>
                  <dt>FCP / TBT</dt><dd>{{ $r->fcp_ms !== null ? number_format($r->fcp_ms / 1000, 1, ',', '') . ' s' : '–' }} / {{ $r->tbt_ms !== null ? $r->tbt_ms . ' ms' : '–' }}</dd>
                  <dt>Serverreactie</dt><dd>{{ $r->ttfb_ms !== null ? $r->ttfb_ms . ' ms' : '–' }}</dd>
                </dl>
              @else
                <p class="muted">Geen geslaagde meting.</p>
              @endif
            </div>
          @endforeach
        </div>
        @php($opportunities = $pagespeed->get('mobile')?->opportunities ?? [])
        @if ($opportunities !== [])
          <h4 class="mt">Grootste besparingen (mobiel)</h4>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Aanbeveling</th><th class="num">Besparing</th><th>Details</th></tr></thead>
              <tbody>
                @foreach ($opportunities as $o)
                  <tr><td class="wrap">{{ $o['title'] }}</td><td class="num">{{ number_format($o['savings_ms'] / 1000, 1, ',', '') }} s</td><td class="muted">{{ $o['display'] ?? '' }}</td></tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
        @if (count($pagespeedHistory) >= 2)
          <h4 class="mt">Trend prestaties mobiel</h4>
          @include('partials.spark', ['values' => $pagespeedHistory, 'class' => 'wide'])
        @endif
      @endif
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
