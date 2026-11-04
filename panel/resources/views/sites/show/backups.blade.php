{{-- Sectie 3: back-ups (met terugzetten) en database opschonen. --}}
@php($statusLabels = ['pending' => 'wordt gemaakt', 'ready' => 'beschikbaar', 'restoring' => 'wordt teruggezet', 'deleted' => 'verwijderd', 'failed' => 'mislukt'])
@php($dotOf = fn ($b) => ['ready' => 'ok', 'failed' => 'crit', 'pending' => 'warn', 'restoring' => 'warn'][$b->status] ?? 'none')

<section class="sd-section" id="sd-backups" data-spy="backups">
  <div class="panel flush">
    <div class="panel-head">
      <span class="sd-ic">@include('partials.icon', ['name' => 'clock', 'size' => 18])</span>
      <h3>Back-ups</h3>
      <span class="hint">{{ config('ocm.backups.keep_days') }} dagen bewaard, of tot de visuele controle in orde is</span>
      @can('manage-sites')
        @if (\App\Ocm\Backups::enabled() && \App\Ocm\Backups::supportsFull($site))
          <div class="right">
            @if ($backups->contains(fn ($b) => $b->status === 'pending' && !empty($b->components['full'])))
              @include('partials.row-action', ['action' => ['kind' => 'done', 'label' => 'Wordt gemaakt']])
            @else
              <form method="POST" action="{{ route('sites.backup.full', $site) }}" class="inline" data-confirm="Volledige back-up maken van {{ $site->name }}? Bij grote sites kan dat even duren.">
                @csrf
                <button class="btn small quiet" type="submit">Nu back-up maken</button>
              </form>
            @endif
          </div>
        @endif
      @endcan
    </div>

    @if ($recentFull = \App\Ocm\Backups::recentFull($site))
      <p class="sd-row-note">Volledige back-up van {{ $recentFull->created_at->timezone('Europe/Amsterdam')->format('H:i') }} {{ $recentFull->status === 'ready' ? 'staat klaar' : 'wordt gemaakt' }}: updates tot {{ $recentFull->created_at->addHours((int) config('ocm.backups.full_reuse_hours'))->timezone('Europe/Amsterdam')->format('H:i') }} gebruiken deze back-up.</p>
    @endif
    @if (!\App\Ocm\Backups::enabled())
      <p class="sd-row-note">Back-ups staan uit: zet de Spaces-gegevens (OCM_SPACES_*) in .env. Updates gaan nu zonder back-up vooraf.</p>
    @elseif (!\App\Ocm\Backups::supported($site))
      <p class="sd-row-note">Vanaf client-plugin 0.5.0. Deze site heeft {{ $site->plugin_version ?? '?' }}; updates gaan zonder back-up vooraf.</p>
    @endif

    <div class="sd-rows">
      @forelse ($backups as $backup)
        <div class="sd-row">
          <span class="sd-dot-sm {{ $dotOf($backup) }}" aria-hidden="true"></span>
          <div class="sd-row-main">
            <span class="sd-row-name">{{ $backup->created_at->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}</span>
            <span class="hint">{{ $backup->describe() }}</span>
            @if ($backup->woocommerce)<span class="badge">webshop</span>@endif
            @if ($backup->status !== 'ready')<span @class(['state', 'bad' => $backup->status === 'failed', 'idle' => $backup->status !== 'failed'])>{{ $statusLabels[$backup->status] ?? $backup->status }}</span>@endif
            @if ($backup->restored_at)<span class="hint">teruggezet {{ $backup->restored_at->timezone('Europe/Amsterdam')->format('d-m H:i') }}</span>@endif
            @if ($backup->error)<div class="note">{{ $backup->error }}</div>@endif
          </div>
          <span class="sd-size">{{ $backup->size() > 0 ? number_format($backup->size() / 1048576, 1, ',', '.') . ' MB' : '' }}</span>
          <div class="sd-row-action">
            @can('manage-sites')
              @if ($backup->status === 'ready')
                <details class="sd-restore">
                  <summary class="btn small quiet">Terugzetten</summary>
                  <form method="POST" action="{{ route('sites.backup.restore', [$site, $backup]) }}" class="sd-restore-form" data-confirm="Back-up van {{ $backup->created_at->timezone('Europe/Amsterdam')->format('d-m H:i') }} terugzetten op {{ $site->name }}?">
                    @csrf
                    <label class="check"><input type="checkbox" name="database" value="1" @checked(!$backup->woocommerce)> ook de database</label>
                    @if ($backup->woocommerce)
                      <label class="check"><input type="checkbox" name="confirm_orders" value="1"> Bestellingen en klanten van na {{ $backup->created_at->timezone('Europe/Amsterdam')->format('d-m H:i') }} gaan verloren</label>
                    @endif
                    <div class="row-actions">
                      <button class="btn small" type="submit">Terugzetten</button>
                    </div>
                  </form>
                  <form method="POST" action="{{ route('sites.backup.delete', [$site, $backup]) }}" class="inline" data-confirm="Back-up verwijderen?">
                    @csrf
                    @method('DELETE')
                    <button class="link-btn" type="submit">Back-up verwijderen</button>
                  </form>
                </details>
              @elseif ($backup->status === 'restoring')
                @include('partials.row-action', ['action' => ['kind' => 'done', 'label' => 'Wordt teruggezet']])
              @endif
            @endcan
          </div>
        </div>
      @empty
        <p class="sd-row-note">Nog geen back-ups in de afgelopen week. Bij elke update wordt er vooraf een gemaakt.</p>
      @endforelse
    </div>
  </div>

  @php($cleanup = ($site->inventory ?? [])['cleanup'] ?? null)
  <details class="panel sd-details">
    <summary class="panel-head">
      <h3>Database opschonen</h3>
      @if (isset($cleanup['db_bytes']))<span class="hint">database {{ number_format($cleanup['db_bytes'] / 1048576, 1, ',', '.') }} MB · autoload {{ number_format(($cleanup['autoload_bytes'] ?? 0) / 1024, 0, ',', '.') }} kB</span>@endif
    </summary>
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
  </details>
</section>
