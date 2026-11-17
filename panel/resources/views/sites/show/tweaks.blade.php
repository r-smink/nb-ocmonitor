{{-- Kant-en-klare schakelaars op de site (client-plugin 0.10.0+). --}}
@php($tweaks = $inventory['tweaks'] ?? null)
@php($tweakBlocked = \App\Ocm\TweakCatalog::blockedReason($site))
<section class="sd-section" id="sd-schakelaars" data-spy="schakelaars">
  <div class="panel">
    <div class="panel-head">
      <span class="sd-ic">@include('partials.icon', ['name' => 'check', 'size' => 18])</span>
      <h3>Schakelaars</h3>
      <span class="hint">Veelgebruikte aanpassingen zonder code in functions.php. Ook in bulk via Bulk-acties.</span>
    </div>
    @if ($tweakBlocked)
      <p class="muted">{{ $tweakBlocked }}.</p>
    @else
      <form method="POST" action="{{ route('sites.tweaks', $site) }}" class="form">
        @csrf
        @foreach (collect(\App\Ocm\TweakCatalog::switchesFor($site))->groupBy(fn ($s) => $s[2], true) as $group => $switches)
          <h4>{{ $group }}</h4>
          <div class="check-grid">
            @foreach ($switches as $key => [$label, $help])
              <label title="{{ $help }}"><input type="checkbox" name="{{ $key }}" value="1" @checked(!empty($tweaks[$key])) @cannot('manage-sites') disabled @endcannot> {{ $label }}</label>
            @endforeach
          </div>
          @if ($group === 'E-mail' && !empty($inventory['smtp']['enabled']))
            @php($smtp = $inventory['smtp'])
            <p class="note">Op de site:
              @if (!empty($smtp['active']))<span class="chip ok">SMTP actief</span> {{ $smtp['host'] }}:{{ $smtp['port'] }}@else<span class="chip warn">nog niet actief</span> {{ $smtp['reason'] ?? '' }}@endif
              @if (!empty($smtp['last_test']))· laatste testmail {{ !empty($smtp['last_test']['ok']) ? 'gelukt' : 'mislukt' }} op {{ \Illuminate\Support\Carbon::createFromTimestamp((int) $smtp['last_test']['time'])->timezone(config('app.timezone'))->format('d-m-Y H:i') }}@endif
            </p>
          @endif
        @endforeach
        <div class="form-row mt">
          <label>Revisies bewaren
            <select name="revisions" @cannot('manage-sites') disabled @endcannot>
              @foreach (\App\Ocm\TweakCatalog::REVISIONS as $value => $label)<option value="{{ $value }}" @selected((int) ($tweaks['revisions'] ?? -1) === $value)>{{ $label }}</option>@endforeach
            </select>
          </label>
          <label>Heartbeat-API
            <select name="heartbeat" @cannot('manage-sites') disabled @endcannot>
              @foreach (\App\Ocm\TweakCatalog::HEARTBEAT as $value => $label)<option value="{{ $value }}" @selected(($tweaks['heartbeat'] ?? 'default') === $value)>{{ $label }}</option>@endforeach
            </select>
          </label>
        </div>
        @can('manage-sites')<div class="row-actions mt"><button class="btn small" type="submit">Opslaan op de site</button></div>@endcan
        <p class="note mt">Beweeg over een schakelaar voor uitleg. Elke wijziging komt in het wijzigingslog van de site.</p>
      </form>
    @endif
  </div>
</section>
