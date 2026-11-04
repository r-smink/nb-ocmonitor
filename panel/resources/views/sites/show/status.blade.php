{{-- Sectie 1: status. Kop, aandachtspunten, vier kerncijfers en daaronder notities, details en beschikbaarheid. --}}
@php($urgent = $issues->whereIn('level', ['crit', 'warn']))
@php($lvlOf = fn (?float $p) => $p === null ? 'none' : ($p < 99 ? 'crit' : ($p < 99.9 ? 'warn' : 'ok')))
@php($upLevel = $site->uptime_state === 'down' ? 'crit' : $lvlOf($uptime['percent']))
@php($sslDays = $site->ssl_expires_at ? (int) now()->startOfDay()->diffInDays($site->ssl_expires_at->copy()->startOfDay(), false) : null)
@php($sslLevel = $site->ssl_error ? 'crit' : ($sslDays === null ? 'none' : ($sslDays <= 3 ? 'crit' : ($sslDays <= (int) config('ocm.monitoring.ssl_warn_days', 14) ? 'warn' : 'ok'))))
@php($lastBackup = $backups->firstWhere('status', 'ready') ?? $backups->first())
@php($backupLevel = $lastBackup === null ? 'none' : ($lastBackup->status === 'failed' ? 'crit' : ($lastBackup->status === 'ready' ? 'ok' : 'warn')))
@php($ms = $site->response_ms)
@php($msLevel = $site->uptime_state === 'down' ? 'crit' : ($ms === null ? 'none' : ($ms < 1500 ? 'ok' : ($ms < 4000 ? 'warn' : 'crit'))))

<section class="sd-section" id="sd-status" data-spy="status">
  <div class="sd-head lvl-{{ $level }}">
    <span class="sd-dot" aria-hidden="true"></span>
    <div class="sd-title-wrap">
      <h1 class="sd-title">{{ $site->name }}</h1>
      <div class="sd-sub">
        @if ($site->customer)<a href="{{ route('customers.show', $site->customer) }}">{{ $site->customer->name }}</a> · @elseif ($site->client_name){{ $site->client_name }} · @endif
        WordPress {{ $site->wp_version ?? '–' }} · PHP {{ $site->php_version ?? '–' }}
        @include('partials.tags', ['tags' => $site->allTags()])
      </div>
    </div>
    <div class="right">
      <span class="lvl-label">{{ ['ok' => 'In orde', 'warn' => 'Aandacht', 'crit' => 'Kritiek', 'pending' => 'Niet gekoppeld'][$level] }}</span>
      <a class="btn ghost small" href="{{ $site->url }}" target="_blank" rel="noopener noreferrer">Site openen @include('partials.icon', ['name' => 'external', 'size' => 14])</a>
    </div>
  </div>

  @if ($urgent->isNotEmpty() && $paired)
    <div @class(['sd-issues', 'crit' => $urgent->contains('level', 'crit')])>
      <ul>
        @foreach ($urgent as $issue)<li><b>{{ \App\Ocm\SiteIssues::CATEGORIES[$issue['category']] }}:</b> {{ $issue['text'] }}</li>@endforeach
      </ul>
      @if ($site->last_error)<p>Laatste fout: {{ $site->last_error }}</p>@endif
    </div>
  @endif

  @if ($paired)
    <div class="kpis sd-metrics">
      <div class="kpi lvl-{{ $upLevel }}">
        <span class="kpi-head"><span class="kpi-ic">@include('partials.icon', ['name' => 'activity', 'size' => 18])</span>Beschikbaarheid</span>
        <b>{{ $site->uptime_state === 'down' ? 'offline' : ($uptime['percent'] !== null ? number_format($uptime['percent'], 2, ',', '') . '%' : '–') }}</b>
        <small>@if ($site->uptime_state === 'down' && $site->down_since)sinds {{ $site->down_since->timezone('Europe/Amsterdam')->format('d-m H:i') }}@else 30 dagen · {{ $uptime['incidents'] }} storing{{ $uptime['incidents'] === 1 ? '' : 'en' }}@endif</small>
      </div>
      <div class="kpi lvl-{{ $sslLevel }}">
        <span class="kpi-head"><span class="kpi-ic">@include('partials.icon', ['name' => 'lock', 'size' => 18])</span>SSL-certificaat</span>
        <b>{{ $site->ssl_error && $sslDays === null ? 'fout' : ($sslDays === null ? '–' : ($sslDays < 0 ? 'verlopen' : $sslDays . ' d')) }}</b>
        <small>{{ $site->ssl_error ? \Illuminate\Support\Str::limit($site->ssl_error, 60) : ($site->ssl_expires_at ? 'tot ' . $site->ssl_expires_at->format('d-m-Y') . ($site->ssl_issuer ? ' · ' . $site->ssl_issuer : '') : 'nog niet gecontroleerd') }}</small>
      </div>
      <div class="kpi lvl-{{ $backupLevel }}">
        <span class="kpi-head"><span class="kpi-ic">@include('partials.icon', ['name' => 'clock', 'size' => 18])</span>Laatste back-up</span>
        <b>{{ $lastBackup ? $lastBackup->created_at->locale('nl')->shortRelativeDiffForHumans() : '–' }}</b>
        <small>{{ $lastBackup ? ($lastBackup->status === 'ready' ? $lastBackup->describe() : ['pending' => 'wordt gemaakt', 'failed' => 'mislukt', 'restoring' => 'wordt teruggezet', 'deleted' => 'verwijderd'][$lastBackup->status] ?? $lastBackup->status) : (\App\Ocm\Backups::enabled() ? 'nog geen back-up deze week' : 'back-ups staan uit') }}</small>
      </div>
      <div class="kpi lvl-{{ $msLevel }}">
        <span class="kpi-head"><span class="kpi-ic">@include('partials.icon', ['name' => 'gauge', 'size' => 18])</span>Responstijd</span>
        <b>{{ $ms !== null ? ($ms >= 1000 ? number_format($ms / 1000, 1, ',', '') . ' s' : $ms . ' ms') : '–' }}</b>
        <small>homepage{{ $site->uptime_checked_at ? ', ' . $site->uptime_checked_at->diffForHumans() : '' }}</small>
      </div>
    </div>
  @else
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

  @php($notes = $site->notes()->with('user')->get())
  <div class="panel" id="notities">
    <div class="panel-head"><h3>Notities</h3>@if ($notes->isNotEmpty())<span class="count-pill">{{ $notes->count() }}</span>@endif<span class="hint">Afspraken en bijzonderheden, bijv. "klant wil in december geen updates".</span></div>
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

  <details class="panel sd-details">
    <summary class="panel-head"><h3>Details</h3><span class="hint">versies, domein, cron, koppeling en aanbevelingen</span></summary>
    @can('manage-sites')
      @if ($paired)
        <div class="row-actions mb">
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
    <dl class="kv">
      <dt>Adres</dt><dd><a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer">{{ $site->url }}</a> <span class="muted mono">{{ $site->site_id }}</span></dd>
      <dt>WordPress</dt><dd>{{ $site->wp_version ?? '–' }}</dd>
      <dt>PHP</dt><dd>{{ $site->php_version ?? '–' }}</dd>
      <dt>Plugin</dt><dd>{{ $site->plugin_version ?? '–' }}</dd>
      <dt>Laatst gezien</dt><dd>{{ $site->last_seen_at?->timezone('Europe/Amsterdam')->format('d-m-Y H:i') ?? '–' }}</dd>
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
        @if ($site->event_gap_at)<span class="badge sec">gat sinds {{ $site->event_gap_at->timezone('Europe/Amsterdam')->format('d-m H:i') }}</span>@endif
      </dd>
    </dl>
    @php($tips = $issues->where('level', 'info'))
    @if ($tips->isNotEmpty() && $paired)
      <h4 class="mt">Aanbevelingen</h4>
      <ul class="issues">
        @foreach ($tips as $issue)<li class="info">{{ $issue['text'] }}</li>@endforeach
      </ul>
    @endif
    @can('manage-sites')
      @php($allTags = \App\Models\Tag::query()->orderBy('name')->get())
      @if ($allTags->isNotEmpty())
        <h4 class="mt">Tags</h4>
        <form method="POST" action="{{ route('sites.tags', $site) }}" class="check-grid">
          @csrf
          @foreach ($allTags as $tag)<label><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked($site->tags->contains('id', $tag->id))> <span class="tag {{ $tag->color }}">{{ $tag->name }}</span></label>@endforeach
          <div><button class="btn ghost small" type="submit">Opslaan</button></div>
        </form>
        @if ($site->customer && $site->customer->tags->isNotEmpty())<p class="note">Via de klant: {{ $site->customer->tags->pluck('name')->implode(', ') }}.</p>@endif
      @endif
    @endcan
  </details>

  @if ($site->uptime_since)
    <div class="panel" id="beschikbaarheid">
      <div class="panel-head">
        <h3>Beschikbaarheid per maand</h3>
        <span class="hint">gemeten sinds {{ $site->uptime_since->timezone('Europe/Amsterdam')->format('d-m-Y') }}, elke 5 minuten</span>
      </div>
      <div class="sla-months">
        @foreach (array_reverse($monthly) as $m)
          <div @class(['sla-month', 'none' => $m['percent'] === null, 'warn' => $m['percent'] !== null && $m['percent'] < 99.9 && $m['percent'] >= 99, 'crit' => $m['percent'] !== null && $m['percent'] < 99])>
            <span class="sla-label">{{ $m['month']->locale('nl')->translatedFormat('M Y') }}</span>
            <b>{{ $m['percent'] !== null ? number_format($m['percent'], 2, ',', '') . '%' : '–' }}</b>
            <small>{{ $m['incidents'] }} storing{{ $m['incidents'] === 1 ? '' : 'en' }}@if ($m['down_minutes'] > 0) · {{ $m['down_minutes'] }} min @endif</small>
          </div>
        @endforeach
      </div>
      @if ($incidents->isNotEmpty())
        <details class="mt">
          <summary>Storingen ({{ $incidents->count() }})</summary>
          <div class="table-wrap mt">
            <table>
              <thead><tr><th>Begin</th><th>Einde</th><th>Duur</th><th>Oorzaak</th></tr></thead>
              <tbody>
                @foreach ($incidents as $incident)
                  <tr>
                    <td>{{ $incident->started_at->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}</td>
                    <td>@if ($incident->ended_at){{ $incident->ended_at->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}@else<span class="badge sec">nog offline</span>@endif</td>
                    <td>{{ $incident->durationLabel() }}</td>
                    <td>{{ $incident->cause ?? '–' }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </details>
      @endif
    </div>
  @endif
</section>
