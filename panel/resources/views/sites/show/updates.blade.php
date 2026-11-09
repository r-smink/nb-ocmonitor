{{-- Sectie 2: openstaande updates, per regel "van → naar" met een knop; daaronder de volledige inventaris en de visuele controle. --}}
@php($canUpdate = auth()->user()->can('manage-sites') && $updatesBlocked === null)
@php($order = ['core' => 0, 'client' => 1, 'plugin' => 2, 'theme' => 3])
@php($rows = $updates->sortBy(fn ($u) => $order[$u['type']] . strtolower($u['name']))->values())
@php($isQueued = fn (array $u) => in_array($u['id'], $queued[$u['type']] ?? [], true))
@php($bulk = $rows->whereIn('type', ['plugin', 'theme', 'client'])->reject($isQueued)->map(fn ($u) => $site->site_id . '|' . $u['type'] . '|' . $u['id'])->values())
@php($translations = (int) ($inventory['translations_pending'] ?? 0))
@php($typeLabels = ['core' => 'WordPress', 'client' => 'OC-plugin', 'plugin' => 'Plugin', 'theme' => 'Thema'])

<section class="sd-section" id="sd-updates" data-spy="updates">
  <div class="panel flush">
    <div class="panel-head">
      <span class="sd-ic">@include('partials.icon', ['name' => 'refresh', 'size' => 18])</span>
      <h3>Openstaande updates</h3>
      @if ($rows->isNotEmpty())<span class="count-pill">{{ $rows->count() }}</span>@endif
      @if ($canUpdate && $bulk->count() > 1)
        <div class="right">
          <form method="POST" action="{{ route('updates.store') }}" class="inline" data-confirm="{{ $bulk->count() }} updates bijwerken op {{ $site->name }}? WordPress zelf gaat apart.">
            @csrf
            @foreach ($bulk as $item)<input type="hidden" name="items[]" value="{{ $item }}">@endforeach
            <button class="btn small" type="submit">Alles bijwerken ({{ $bulk->count() }})</button>
          </form>
        </div>
      @endif
    </div>

    @if ($updatesBlocked && $rows->isNotEmpty())
      <p class="sd-row-note">Bijwerken vanuit het panel kan hier niet: {{ $updatesBlocked }}.</p>
    @endif

    <div class="sd-rows">
      @foreach ($rows as $u)
        <div class="sd-row">
          <div class="sd-row-main">
            <span class="sd-row-name">{{ $u['name'] }}</span>
            <span class="hint">{{ $typeLabels[$u['type']] ?? $u['type'] }}</span>
            @if (isset($security[$u['type'] . ':' . strtolower((string) $u['slug'])]))<span class="badge sec">beveiliging</span>@endif
            @include('partials.risk-badge', ['risk' => $risks[$u['type'] . ':' . $u['id']] ?? null])
            @if (!empty($risks[$u['type'] . ':' . $u['id']]['summary']))<div class="risk-summary">{{ $risks[$u['type'] . ':' . $u['id']]['summary'] }}</div>@endif
          </div>
          <div class="sd-ver"><span class="mono">{{ $u['from'] }}</span> <span class="muted">→</span> <span class="ver-to">{{ $u['to'] }}</span></div>
          <div class="sd-row-action">
            @if ($isQueued($u))
              @include('partials.row-action', ['action' => ['kind' => 'done', 'label' => 'In wachtrij']])
            @elseif ($canUpdate)
              <form method="POST" action="{{ route('sites.updates', $site) }}" class="row-form" @if ($u['type'] === 'core') data-confirm="WordPress op {{ $site->name }} bijwerken naar {{ $u['to'] }}? Voor WordPress zelf is er geen automatische rollback." @endif>
                @csrf
                <input type="hidden" name="type" value="{{ $u['type'] }}">
                @if (in_array($u['type'], ['plugin', 'theme'], true))<input type="hidden" name="ids[]" value="{{ $u['id'] }}">@endif
                <button @class(['btn', 'small', 'quiet' => !isset($security[$u['type'] . ':' . strtolower((string) $u['slug'])])]) type="submit">Bijwerken</button>
              </form>
            @endif
          </div>
        </div>
      @endforeach
      @if ($translations > 0)
        <div class="sd-row">
          <div class="sd-row-main"><span class="sd-row-name">Vertalingen</span><span class="hint">{{ $translations }} taalpakket{{ $translations === 1 ? '' : 'ten' }}</span></div>
          <div class="sd-row-action">
            @if (in_array('translations', $queued['translations'] ?? [], true))
              @include('partials.row-action', ['action' => ['kind' => 'done', 'label' => 'In wachtrij']])
            @elseif ($canUpdate)
              <form method="POST" action="{{ route('sites.updates', $site) }}" class="row-form">
                @csrf
                <input type="hidden" name="type" value="translations">
                <button class="btn small quiet" type="submit">Bijwerken</button>
              </form>
            @endif
          </div>
        </div>
      @endif
      @if ($rows->isEmpty() && $translations === 0)
        <div class="sd-ok">@include('partials.icon', ['name' => 'check', 'size' => 18]) <span>Alles is bijgewerkt{{ $site->inventory_at ? ' (inventaris van ' . $site->inventory_at->diffForHumans() . ')' : '' }}.</span></div>
      @endif
    </div>
  </div>

  @if ($inventory !== [])
    @php($plugins = collect($inventory['plugins'] ?? [])->sortBy(fn ($p) => strtolower($p['name'] ?? '')))
    @php($themes = collect($inventory['themes'] ?? []))
    @php($ago = fn (?\Carbon\CarbonInterface $at) => $at ? $at->timezone('Europe/Amsterdam')->format('d-m-Y') : '–')
    <details class="panel sd-details">
      <summary class="panel-head"><h3>Geïnstalleerd</h3><span class="hint">{{ $plugins->count() }} plugins · {{ $themes->count() }} thema's · WordPress {{ $inventory['core']['version'] ?? '' }}</span></summary>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Plugin</th><th>Versie</th><th>Update</th><th>Actief</th><th>Laatst bijgewerkt</th></tr></thead>
          <tbody>
            @foreach ($plugins as $plugin)
              <tr>
                <td class="wrap">{{ $plugin['name'] ?? $plugin['file'] }}@if (!empty($plugin['auto_update'])) <span class="badge" title="Auto-update staat aan op de site">auto</span>@endif</td>
                <td class="mono">{{ $plugin['version'] ?? '' }}</td>
                <td @class(['lvl-warn' => !empty($plugin['update'])])>{{ $plugin['update']['version'] ?? '' }}</td>
                <td>{{ !empty($plugin['active']) ? 'ja' : 'nee' }}</td>
                <td>{{ $ago($lastUpdated['plugin:' . ($plugin['file'] ?? '')] ?? null) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="table-wrap mt">
        <table>
          <thead><tr><th>Thema</th><th>Versie</th><th>Update</th><th>Actief</th><th>Laatst bijgewerkt</th></tr></thead>
          <tbody>
            @foreach ($themes as $theme)
              <tr>
                <td>{{ $theme['name'] ?? $theme['stylesheet'] }}</td>
                <td class="mono">{{ $theme['version'] ?? '' }}</td>
                <td @class(['lvl-warn' => !empty($theme['update'])])>{{ $theme['update']['version'] ?? '' }}</td>
                <td>{{ !empty($theme['active']) ? 'ja' : '' }}</td>
                <td>{{ $ago($lastUpdated['theme:' . ($theme['stylesheet'] ?? '')] ?? null) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <p class="note mt">Premium-thema's en -plugins met een eigen updater verschijnen pas bij de updates als die updater de update aan WordPress doorgeeft.</p>
    </details>
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
</section>
