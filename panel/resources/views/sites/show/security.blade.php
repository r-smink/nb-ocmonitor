{{-- Sectie 4: kwetsbaarheden van deze site en de integriteitscontrole. --}}
<section class="sd-section" id="sd-beveiliging" data-spy="beveiliging">
  @if (\App\Ocm\Menu::enabled('vulnerabilities'))
    <div class="panel flush">
      <div class="panel-head">
        <span class="sd-ic">@include('partials.icon', ['name' => 'shield', 'size' => 18])</span>
        <h3>Beveiliging</h3>
        @if ($vulns->isNotEmpty())<span @class(['count-pill', 'crit' => $vulns->contains('level', 'crit')])>{{ $vulns->count() }} kwetsba{{ $vulns->count() === 1 ? 'arheid' : 'arheden' }}</span>@endif
        <div class="right"><a class="more" href="{{ route('vulnerabilities.index') }}">Alle kwetsbaarheden →</a></div>
      </div>
      @if ($vulns->isEmpty())
        <div class="sd-ok">@include('partials.icon', ['name' => 'check', 'size' => 18]) <span>{{ \App\Ocm\Vulnerabilities::enabled() ? 'Geen bekende kwetsbaarheden in de geïnstalleerde versies.' : 'Nog geen kwetsbaarhedenfeed: zet een Wordfence API-sleutel onder Instellingen.' }}</span></div>
      @else
        <div class="vuln-list sd-vulns">
          @foreach ($vulns as $m)
            @php($v = $m['vulnerability'])
            <div class="vuln {{ $m['level'] }}">
              <span @class(['cvss', 'crit' => $m['level'] === 'crit', 'warn' => $m['level'] === 'warn']) title="CVSS-score">{{ $v->cvss_score !== null ? number_format($v->cvss_score, 1) : ($v->informational ? 'info' : '?') }}</span>
              <div class="vuln-body">
                <div class="task-meta"><span class="badge">{{ $m['component']['name'] }} <span class="ver">{{ $m['component']['version'] }}</span></span></div>
                <div class="task-text"><a href="{{ $v->url() }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($v->title ?? 'Kwetsbaarheid', 160) }}</a>@if ($v->cve) <span class="mono">{{ $v->cve }}</span>@endif</div>
              </div>
              <div class="vuln-fix">
                @if ($m['fixed'])<span class="hint">opgelost in</span> <span class="ver-to">{{ $m['fixed'] }}</span>@else<span class="state bad">nog geen oplossing</span>@endif
              </div>
              <div class="task-actions">
                @if ($m['action'])@include('partials.row-action', ['action' => $m['action'], 'primary' => $m['level'] === 'crit'])@endif
              </div>
            </div>
          @endforeach
        </div>
        <p class="sd-row-note">{{ \App\Ocm\Vulnerabilities::ATTRIBUTION }}</p>
      @endif
    </div>
  @endif

  @php($int = $site->integrity)
  <div class="panel" id="integriteit">
    <div class="panel-head">
      <h3>Integriteit</h3>
      <span class="hint">{{ $site->integrity_at ? 'gecontroleerd ' . $site->integrity_at->diffForHumans() . ', dagelijks' : 'WordPress en plugins tegen de checksums van wordpress.org' }}</span>
      @can('manage-sites')
        @if ($site->supports('0.9.0'))
          <div class="right">
            @if ($jobs->contains(fn ($j) => $j->command === 'security.integrity' && in_array($j->status, ['pending', 'running'], true)))
              @include('partials.row-action', ['action' => ['kind' => 'done', 'label' => 'Aangevraagd']])
            @else
              <form method="POST" action="{{ route('sites.command', $site) }}" class="inline">
                @csrf
                <input type="hidden" name="command" value="security.integrity">
                <button class="btn ghost small" type="submit">Nu controleren</button>
              </form>
            @endif
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
</section>
