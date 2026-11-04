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
    <p class="dsub"><a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer">{{ $site->url }}</a> · {{ $site->site_id }} @include('partials.tags', ['tags' => $site->allTags()])</p>
    @can('manage-sites')
      @php($allTags = \App\Models\Tag::query()->orderBy('name')->get())
      @if ($allTags->isNotEmpty())
        <details class="tag-edit">
          <summary>Tags wijzigen</summary>
          <form method="POST" action="{{ route('sites.tags', $site) }}" class="check-grid mt">
            @csrf
            @foreach ($allTags as $tag)<label><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked($site->tags->contains('id', $tag->id))> <span class="tag {{ $tag->color }}">{{ $tag->name }}</span></label>@endforeach
            <div><button class="btn ghost small" type="submit">Opslaan</button></div>
          </form>
          @if ($site->customer && $site->customer->tags->isNotEmpty())<p class="note">Via de klant: {{ $site->customer->tags->pluck('name')->implode(', ') }}.</p>@endif
        </details>
      @endif
    @endcan
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

  @php($notes = $site->notes()->with('user')->get())
  <div class="panel" id="notities">
    <div class="panel-head"><h3>Notities</h3>@if ($notes->isNotEmpty())<span class="badge">{{ $notes->count() }}</span>@endif<span class="hint">Afspraken en bijzonderheden, bijv. "klant wil in december geen updates".</span></div>
    @if ($notes->isNotEmpty())
      <ul class="notes">
        @foreach ($notes as $note)
          <li @class(['note-item', 'pinned' => $note->pinned])>
            <div class="note-body">{{ $note->body }}</div>
            <div class="note-meta">
              {{ $note->user?->name ?? 'Onbekend' }} · {{ $note->created_at->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}@if ($note->pinned) · <b>vastgezet</b>@endif
              @can('manage-sites')
                <form method="POST" action="{{ route('sites.notes.pin', [$site, $note]) }}" class="inline">
                  @csrf
                  <button class="link-btn" type="submit">{{ $note->pinned ? 'Losmaken' : 'Vastzetten' }}</button>
                </form>
                @if (auth()->user()->isAdmin() || $note->user_id === auth()->id())
                  <form method="POST" action="{{ route('sites.notes.destroy', [$site, $note]) }}" class="inline" data-confirm="Deze notitie verwijderen?">
                    @csrf
                    @method('DELETE')
                    <button class="link-btn" type="submit">Verwijderen</button>
                  </form>
                @endif
              @endcan
            </div>
          </li>
        @endforeach
      </ul>
    @endif
    @can('manage-sites')
      <form method="POST" action="{{ route('sites.notes.store', $site) }}" class="form mt">
        @csrf
        <label>Nieuwe notitie<textarea name="body" rows="2" maxlength="2000" required></textarea></label>
        <div class="actions">
          <button class="btn small" type="submit">Toevoegen</button>
          <label class="check"><input type="checkbox" name="pinned" value="1"> Vastzetten (ook zichtbaar op de sitekaart en bij updates)</label>
        </div>
      </form>
    @endcan
  </div>

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
        <dt>Uptime (30 dagen)</dt>
        <dd>
          @if ($site->uptime_state === 'down')<span class="badge sec">offline{{ $site->down_since ? ' sinds ' . $site->down_since->timezone('Europe/Amsterdam')->format('d-m H:i') : '' }}</span>@endif
          {{ $uptime['percent'] !== null ? number_format($uptime['percent'], 2, ',', '') . '%' : 'nog geen metingen' }}
          @if ($uptime['incidents'] > 0)<span class="muted">· {{ $uptime['incidents'] }} storing(en), {{ $uptime['down_minutes'] }} min</span>@endif
          @if ($site->response_ms)<span class="muted">· {{ $site->response_ms }} ms</span>@endif
        </dd>
        <dt>SSL</dt>
        <dd>
          @if ($site->ssl_expires_at)
            <span @class(['issue-text', 'warn' => $site->ssl_expires_at->lt(now()->addDays(14)), 'crit' => $site->ssl_expires_at->lt(now()->addDays(3))])>tot {{ $site->ssl_expires_at->format('d-m-Y') }}</span>
            <span class="muted">{{ $site->ssl_issuer }}</span>
          @endif
          @if ($site->ssl_error)<span class="issue-text crit">{{ $site->ssl_error }}</span>@endif
          @if (!$site->ssl_expires_at && !$site->ssl_error)–@endif
        </dd>
        <dt>Domein</dt>
        <dd>
          @if ($site->domain_expires_at)
            <span @class(['issue-text', 'warn' => $site->domain_expires_at->lt(now()->addDays(30))])>verloopt {{ $site->domain_expires_at->format('d-m-Y') }}</span>
          @else
            <span class="muted">{{ $site->domain_error ?? '–' }}</span>
          @endif
        </dd>
        @php($cron = ($site->inventory ?? [])['cron'] ?? null)
        @if (is_array($cron))
          <dt>WP-Cron</dt>
          <dd>@if (($cron['overdue'] ?? 0) > 0 && ($cron['oldest_overdue_seconds'] ?? 0) >= 21600)<span class="issue-text warn">{{ $cron['overdue'] }} taak/taken te laat</span>@else{{ $cron['events'] ?? 0 }} geplande taken, op tijd @endif @if (!empty($cron['disabled']))<span class="muted">· via servercron</span>@endif</dd>
        @endif
        @php($maint = ($site->inventory ?? [])['maintenance_mode'] ?? null)
        @if (is_array($maint) && !empty($maint['enabled']))
          <dt>Onderhoudsmodus</dt><dd><span class="badge sec">aan</span>@if (!empty($maint['until'])) <span class="muted">tot {{ \Illuminate\Support\Carbon::createFromTimestamp((int) $maint['until'])->timezone('Europe/Amsterdam')->format('d-m H:i') }}</span>@endif</dd>
        @endif
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

    @php($clientUpdate = $updates->get('client:nb-ocmonitor-client'))
    @if ($clientUpdate)
      <div class="alert warn">
        Client-plugin {{ $clientUpdate['from'] }} → {{ $clientUpdate['to'] }} beschikbaar (ondertekende release).
        @if ($canUpdate)
          @if (in_array('nb-ocmonitor-client', $queued['client'] ?? [], true))
            <span class="state idle">in wachtrij</span>
          @else
            <form method="POST" action="{{ route('sites.updates', $site) }}" class="inline">
              @csrf
              <input type="hidden" name="type" value="client">
              <button class="btn small" type="submit">Client-plugin bijwerken</button>
            </form>
          @endif
        @endif
      </div>
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

  @if ($site->isPaired() && \App\Ocm\Menu::enabled('pagespeed'))
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

  @if ($site->isPaired())
    @php($int = $site->integrity)
    <div class="panel" id="integriteit">
      <div class="panel-head">
        <h3>Integriteit</h3>
        <span class="hint">{{ $site->integrity_at ? 'gecontroleerd ' . $site->integrity_at->diffForHumans() . ', dagelijks' : 'WordPress en plugins tegen de checksums van wordpress.org' }}</span>
        @can('manage-sites')
          @if ($site->supports('0.9.0'))
            <div class="right">
              <form method="POST" action="{{ route('sites.command', $site) }}" class="inline">
                @csrf
                <input type="hidden" name="command" value="security.integrity">
                <button class="btn ghost small" type="submit">Nu controleren</button>
              </form>
            </div>
          @endif
        @endcan
      </div>
      @if (!$site->supports('0.9.0'))
        <p class="muted">Vanaf client-plugin 0.9.0 (deze site: {{ $site->plugin_version ?? '?' }}).</p>
      @elseif (!is_array($int))
        <p class="muted">Nog niet gecontroleerd.</p>
      @else
        @php($core = $int['core'] ?? null)
        @php($pluginRows = collect($int['plugins'] ?? [])->filter(fn ($r) => is_array($r)))
        <dl class="kv">
          <dt>WordPress {{ $int['wp_version'] ?? '' }}</dt>
          <dd>
            @if (!is_array($core))
              <span class="muted">geen checksums beschikbaar voor deze versie</span>
            @elseif (($core['counts']['modified'] ?? 0) + ($core['counts']['unknown'] ?? 0) === 0)
              <span class="state">in orde</span> <span class="muted">{{ $core['checked'] ?? 0 }} bestanden</span>
            @else
              <span class="issue-text crit">{{ $core['counts']['modified'] ?? 0 }} gewijzigd, {{ $core['counts']['unknown'] ?? 0 }} onbekend, {{ $core['counts']['missing'] ?? 0 }} ontbreekt</span>
              <details><summary>Bestanden</summary><ul class="file-list">
                @foreach (array_merge($core['modified'] ?? [], $core['unknown'] ?? []) as $file)<li class="mono">{{ $file }}</li>@endforeach
              </ul></details>
            @endif
          </dd>
          <dt>Hoofdmap</dt>
          <dd>
            @if (empty($int['root_unknown']))<span class="state">in orde</span>@else
              <span class="issue-text crit">onbekende PHP-bestanden:</span> <span class="mono">{{ implode(', ', $int['root_unknown']) }}</span>
            @endif
          </dd>
          <dt>Uploads</dt>
          <dd>
            @if (empty($int['uploads_php_count']))<span class="state">geen PHP-bestanden</span>@else
              <span class="issue-text crit">{{ $int['uploads_php_count'] }} PHP-bestand(en)</span>
              <details><summary>Bestanden</summary><ul class="file-list">@foreach ($int['uploads_php'] ?? [] as $file)<li class="mono">{{ $file }}</li>@endforeach</ul></details>
            @endif
          </dd>
          <dt>Plugins</dt>
          <dd>
            {{ $pluginRows->where('status', 'ok')->count() }} in orde · {{ $pluginRows->where('status', 'modified')->count() }} afwijkend · {{ $pluginRows->where('status', 'unverifiable')->count() }} niet te controleren (premium)
            @if ($pluginRows->where('status', 'skipped')->isNotEmpty()) · {{ $pluginRows->where('status', 'skipped')->count() }} overgeslagen (tijd) @endif
            @foreach ($pluginRows->where('status', 'modified') as $slug => $r)
              <details><summary class="issue-text warn">{{ $slug }}: {{ ($r['counts']['modified'] ?? 0) + ($r['counts']['unknown'] ?? 0) }} bestand(en)</summary><ul class="file-list">
                @foreach (array_merge($r['modified'] ?? [], $r['unknown'] ?? []) as $file)<li class="mono">{{ $file }}</li>@endforeach
              </ul></details>
            @endforeach
          </dd>
        </dl>
        <p class="note">Afwijkingen betekenen niet altijd een hack (een handmatige aanpassing kan ook), maar onbekende PHP-bestanden in de hoofdmap of in uploads zijn bijna altijd verdacht. Er wordt niets automatisch verwijderd.</p>
      @endif
    </div>
  @endif

  @if ($site->isPaired())
    @php($cleanup = ($site->inventory ?? [])['cleanup'] ?? null)
    <div class="panel">
      <div class="panel-head">
        <h3>Database opschonen</h3>
        @if (isset($cleanup['db_bytes']))<span class="hint">database {{ number_format($cleanup['db_bytes'] / 1048576, 1, ',', '.') }} MB · autoload {{ number_format(($cleanup['autoload_bytes'] ?? 0) / 1024, 0, ',', '.') }} kB</span>@endif
      </div>
      @if ($reason = $site->cleanupBlockedReason())
        <p class="muted">{{ $reason }}.</p>
      @elseif (!is_array($cleanup))
        <p class="muted">Nog geen gegevens; vraag een inventaris op.</p>
      @else
        <form method="POST" action="{{ route('sites.cleanup', $site) }}" data-confirm="Deze gegevens definitief verwijderen op {{ $site->name }}?">
          @csrf
          <div class="check-grid">
            @foreach (\App\Http\Controllers\SiteController::CLEANUP_ITEMS as $key => $label)
              @php($n = (int) ($cleanup['counts'][$key] ?? 0))
              <label><input type="checkbox" name="items[]" value="{{ $key }}" @checked($n > 0) @disabled($n === 0)> {{ $label }} <span class="muted">({{ number_format($n, 0, ',', '.') }})</span></label>
            @endforeach
          </div>
          @can('manage-sites')<div class="row-actions mt"><button class="btn small" type="submit">Opschonen</button></div>@endcan
        </form>
        <p class="note mt">Maximaal 2000 per soort per keer. Media: {{ number_format($cleanup['media']['total'] ?? 0, 0, ',', '.') }} bestanden, waarvan {{ number_format($cleanup['media']['unattached'] ?? 0, 0, ',', '.') }} niet aan een bericht gekoppeld. Die worden niet automatisch verwijderd: page builders gebruiken vaak afbeeldingen die nergens "gekoppeld" zijn.</p>
      @endif
    </div>

    <div class="panel">
      <div class="panel-head">
        <h3>Backups vóór updates</h3>
        <span class="hint">Database en de onderdelen die bijgewerkt worden. {{ config('ocm.backups.keep_days') }} dagen bewaard, of tot de visuele controle in orde is.</span>
        @can('manage-sites')
          @if (\App\Ocm\Backups::enabled() && \App\Ocm\Backups::supportsFull($site))
            <div class="right">
              <form method="POST" action="{{ route('sites.backup.full', $site) }}" class="inline" data-confirm="Volledige backup maken van {{ $site->name }}? Bij grote sites kan dat even duren.">
                @csrf
                <button class="btn ghost small" type="submit">Volledige backup maken</button>
              </form>
            </div>
          @endif
        @endcan
      </div>
      @if ($recentFull = \App\Ocm\Backups::recentFull($site))
        <p class="note">Volledige backup van {{ $recentFull->created_at->timezone('Europe/Amsterdam')->format('H:i') }} {{ $recentFull->status === 'ready' ? 'staat klaar' : 'wordt gemaakt' }}: updates tot {{ $recentFull->created_at->addHours((int) config('ocm.backups.full_reuse_hours'))->timezone('Europe/Amsterdam')->format('H:i') }} gebruiken deze backup.</p>
      @endif
      @if (!\App\Ocm\Backups::enabled())
        <p class="note">Staat uit: zet de Spaces-gegevens (OCM_SPACES_*) in .env. Updates gaan nu zonder backup vooraf.</p>
      @elseif (!\App\Ocm\Backups::supported($site))
        <p class="note">Vanaf client-plugin 0.5.0. Deze site heeft {{ $site->plugin_version ?? '?' }}; updates gaan zonder backup vooraf.</p>
      @endif
      @if ($backups->isNotEmpty())
        <div class="table-wrap">
          <table>
            <thead><tr><th>Gemaakt</th><th>Inhoud</th><th class="num">Grootte</th><th>Status</th><th>Bewaard tot</th>@can('manage-sites')<th></th>@endcan</tr></thead>
            <tbody>
              @foreach ($backups as $backup)
                <tr>
                  <td>{{ $backup->created_at->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}</td>
                  <td class="wrap">{{ $backup->describe() }}@if ($backup->woocommerce) <span class="badge">webshop</span>@endif</td>
                  <td class="num">{{ $backup->size() > 0 ? number_format($backup->size() / 1048576, 1, ',', '.') . ' MB' : '' }}</td>
                  <td class="wrap">
                    <span @class(['state', 'bad' => $backup->status === 'failed', 'idle' => in_array($backup->status, ['pending', 'restoring', 'deleted'], true)])>{{ ['pending' => 'wordt gemaakt', 'ready' => 'beschikbaar', 'restoring' => 'wordt teruggezet', 'deleted' => 'verwijderd', 'failed' => 'mislukt'][$backup->status] ?? $backup->status }}</span>
                    @if ($backup->restored_at)<span class="muted">teruggezet {{ $backup->restored_at->timezone('Europe/Amsterdam')->format('d-m H:i') }}</span>@endif
                    @if ($backup->error)<div class="note">{{ $backup->error }}</div>@endif
                  </td>
                  <td>{{ $backup->status === 'ready' ? $backup->expires_at?->timezone('Europe/Amsterdam')->format('d-m-Y') : '' }}</td>
                  @can('manage-sites')
                    <td class="num">
                      @if ($backup->status === 'ready')
                        <form method="POST" action="{{ route('sites.backup.restore', [$site, $backup]) }}" class="stack" data-confirm="Backup van {{ $backup->created_at->timezone('Europe/Amsterdam')->format('d-m H:i') }} terugzetten op {{ $site->name }}?">
                          @csrf
                          <label class="check-grid"><span><input type="checkbox" name="database" value="1" @checked(!$backup->woocommerce)> ook de database</span></label>
                          @if ($backup->woocommerce)
                            <label class="check-grid"><span><input type="checkbox" name="confirm_orders" value="1"> Ik begrijp dat bestellingen en klanten van na {{ $backup->created_at->timezone('Europe/Amsterdam')->format('d-m H:i') }} verloren gaan</span></label>
                          @endif
                          <div class="row-actions">
                            <button class="btn small" type="submit">Terugzetten</button>
                          </div>
                        </form>
                        <form method="POST" action="{{ route('sites.backup.delete', [$site, $backup]) }}" class="inline" data-confirm="Backup verwijderen?">
                          @csrf
                          @method('DELETE')
                          <button class="btn ghost small" type="submit">Verwijderen</button>
                        </form>
                      @endif
                    </td>
                  @endcan
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
  @endif

  @if ($visual)
    <div class="panel">
      <div class="panel-head">
        <h3>Visuele controle na updates</h3>
        <span class="hint">{{ $visual->created_at->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}</span>
        @if ($visual->status === 'done')
          <span @class(['badge', 'sec' => $visual->changed(), 'ok' => !$visual->changed()])>{{ number_format($visual->diff_percent, 1, ',', '') }}% veranderd</span>
        @else
          <span class="state idle">{{ ['before' => 'screenshot vooraf…', 'updating' => 'updates lopen…', 'skipped' => 'overgeslagen'][$visual->status] ?? $visual->status }}</span>
        @endif
        @can('manage-sites')
          @if ($visual->changed() && !$visual->reviewed_at)
            <div class="right">
              <form method="POST" action="{{ route('sites.visual.review', [$site, $visual]) }}" class="inline">@csrf<button class="btn ghost small" type="submit">Bekeken, in orde</button></form>
            </div>
          @endif
        @endcan
      </div>
      @if ($visual->error)<p class="note">{{ $visual->error }}</p>@endif
      @if ($visual->before_image || $visual->after_image)
        <div class="shots">
          @foreach (['before_image' => 'Voor', 'after_image' => 'Na', 'diff_image' => 'Verschil'] as $field => $label)
            @if ($visual->{$field})
              <figure><figcaption>{{ $label }}</figcaption><img src="data:image/jpeg;base64,{{ $visual->{$field} }}" alt="{{ $label }} de updates" loading="lazy"></figure>
            @endif
          @endforeach
        </div>
        <p class="note mt">Screenshots via Google PageSpeed Insights (desktop). Wisselende inhoud zoals sliders of cookiebanners geeft ook verschil; controleer bij een hoog percentage de site zelf.</p>
      @endif
    </div>
  @endif

  @if ($site->isPaired() && \App\Ocm\Menu::enabled('wp-users'))
    <div class="panel">
      <div class="panel-head">
        <h3>WordPress-gebruikers</h3><span class="badge">{{ $wpUsers->count() }}</span>
        @if ($site->wp_users_at)<span class="hint">opgehaald {{ $site->wp_users_at->diffForHumans() }}</span>@endif
        @can('manage-sites')
          @if ($site->usersBlockedReason() === null)
            <div class="right">
              <form method="POST" action="{{ route('sites.two-factor', $site) }}" class="row-actions">
                @csrf
                <label class="hint" for="tfa-mode">2FA afdwingen</label>
                <select id="tfa-mode" name="mode" class="w-auto">
                  @foreach (\App\Http\Controllers\WpUserController::TWO_FACTOR_MODES as $mode => $label)<option value="{{ $mode }}" @selected((($site->wp_users ?? [])['two_factor_mode'] ?? 'off') === $mode)>{{ $label }}</option>@endforeach
                </select>
                <button class="btn ghost small" type="submit">Opslaan</button>
              </form>
            </div>
          @endif
        @endcan
      </div>
      @if ($reason = $site->usersBlockedReason())
        <p class="muted">{{ $reason }}.</p>
      @else
        @if (!empty(($site->wp_users ?? [])['two_factor_disabled']))<p class="note">2FA staat op de site uit via NB_OCM_DISABLE_2FA in wp-config.php.</p>@endif
        @include('partials.wp-users-table', ['rows' => $wpUsers, 'showSite' => false])
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
