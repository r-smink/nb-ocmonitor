{{-- Fatale PHP-fouten die de client-plugin (0.12.0+) op de site heeft opgevangen. --}}
@php
  $phpErrors = \App\Models\PhpError::query()->where('site_id', $site->id)->orderByDesc('last_seen_at')->limit(50)->get();
  $openErrors = $phpErrors->filter->isOpen();
@endphp
<section class="sd-section" id="sd-fouten" data-spy="fouten">
  <div class="panel">
    <div class="panel-head">
      <span class="sd-ic">@include('partials.icon', ['name' => 'bug', 'size' => 18])</span>
      <h3>PHP-fouten</h3>
      @if ($openErrors->isNotEmpty())<span class="count-pill crit">{{ $openErrors->count() }}</span>@endif
      @if (!empty($site->debug_log['size']))<span class="hint">{{ $site->debug_log['path'] }}: {{ number_format($site->debug_log['size'] / 1048576, 1, ',', '.') }} MB</span>@endif
    </div>
    @if ($site->plugin_version === null || version_compare($site->plugin_version, '0.12.0', '<'))
      <p class="muted">Vanaf client-plugin 0.12.0 (deze site: {{ $site->plugin_version ?? '?' }}).</p>
    @elseif ($phpErrors->isEmpty())
      <div class="sd-ok">@include('partials.icon', ['name' => 'check', 'size' => 18]) <span>Geen fatale PHP-fouten opgevangen.</span></div>
    @else
      <div class="table-wrap scroll-sm">
        <table>
          <thead><tr><th>Laatst</th><th>Fout</th><th>Waar</th><th class="num">Aantal</th>@can('manage-sites')<th></th>@endcan</tr></thead>
          <tbody>
            @foreach ($phpErrors as $e)
              <tr @class(['muted' => !$e->isOpen()])>
                <td>{{ $e->last_seen_at->timezone('Europe/Amsterdam')->format('d-m H:i') }}</td>
                <td class="wrap"><b>{{ $e->type }}</b>: {{ \Illuminate\Support\Str::limit($e->message, 220) }}</td>
                <td class="wrap mono">{{ $e->file }}:{{ $e->line }}@if ($e->url)<br><span class="muted">{{ $e->url }}</span>@endif</td>
                <td class="num">{{ $e->count }}</td>
                @can('manage-sites')
                  <td class="num"><form method="POST" action="{{ route('php-errors.resolve', $e) }}" class="inline">@csrf<button class="btn ghost small" type="submit">{{ $e->isOpen() ? 'Opgelost' : 'Heropenen' }}</button></form></td>
                @endcan
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <p class="sd-row-note">Alleen fatale fouten (een pagina die niet laadt). Paden zijn relatief aan de WordPress-map; er gaan geen bezoekersgegevens mee.</p>
    @endif
  </div>
</section>
