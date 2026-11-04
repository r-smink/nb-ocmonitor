@extends('layouts.app')
@section('title', 'Bulk-acties')
@section('content')
  <div class="page-head">
    <div>
      <h1>Bulk-acties</h1>
      <p>Eén actie op meerdere sites tegelijk. Plugins beheren en de onderhoudsmodus kunnen vanaf client-plugin {{ $minVersion }}; installeren alleen vanaf wordpress.org.</p>
    </div>
  </div>

  @can('manage-sites')
    <form method="POST" action="{{ route('bulk.run') }}" class="form">
      @csrf
      {{-- Enter in een veld verstuurt met de eerste knop: die doet niets, zodat er nooit per ongeluk iets gebeurt. --}}
      <button type="submit" name="action" value="" class="hidden" tabindex="-1" aria-hidden="true"></button>
      <div class="cols cols-wide">
        <section class="panel">
          <div class="panel-head">
            <h3>Sites</h3>
            <label class="check"><input type="checkbox" data-check-all="bulk"> alles</label>
            <span class="hint"><span data-check-count="bulk">0</span> gekozen</span>
          </div>
          <div class="check-grid">
            @foreach ($sites as $site)
              <label>
                <input type="checkbox" name="sites[]" value="{{ $site->id }}" data-check="bulk" @checked(in_array($site->id, array_map('intval', old('sites', [])), true))>
                {{ $site->name }}
                @unless ($site->supports($minVersion))<span class="muted">({{ $site->plugin_version ?? '?' }})</span>@endunless
              </label>
            @endforeach
          </div>
          <p class="note mt">Sites met een oudere plugin (tussen haakjes) worden overgeslagen bij plugin-beheer en de onderhoudsmodus.</p>
        </section>

        <section class="panel">
          <h3>WP-gebruiker</h3>
          <div class="stack mt">
            <label>Gebruikersnaam of e-mailadres<input name="login" value="{{ old('login') }}" maxlength="191" autocomplete="off"></label>
            <div class="row-actions">
              <button class="btn small danger" type="submit" name="action" value="user_lock" data-confirm-button="Deze gebruiker blokkeren op alle (gekozen) sites waar hij bestaat?">Blokkeren</button>
              <button class="btn small quiet" type="submit" name="action" value="user_logout">Overal uitloggen</button>
            </div>
            <p class="note">Op elke site waar deze gebruiker bestaat (volgens de laatste gebruikerslijst). Kies je sites hiernaast, dan alleen daar.</p>
          </div>
        </section>
      </div>

      <div class="cols">
        <section class="panel">
          <h3>Plugins</h3>
          <div class="stack mt">
            <div class="form-row">
              <label>Installeren (slug op wordpress.org)<input name="slug" value="{{ old('slug') }}" maxlength="100" placeholder="bijv. wordfence" autocomplete="off" spellcheck="false"></label>
              <label class="check self-end"><input type="checkbox" name="activate" value="1" @checked(old('activate'))> ook activeren</label>
            </div>
            <div><button class="btn small" type="submit" name="action" value="install" data-confirm-button="Deze plugin installeren op de gekozen sites?">Installeren</button></div>
            <label>Of kies een geïnstalleerde plugin
              <select name="plugin">
                <option value="">–</option>
                @foreach ($plugins as $p)
                  <option value="{{ $p['file'] }}" @selected(old('plugin') === $p['file'])>{{ $p['name'] }} · op {{ $p['sites'] }} site(s), {{ $p['active'] }} actief</option>
                @endforeach
              </select>
            </label>
            <div class="row-actions">
              <button class="btn small quiet" type="submit" name="action" value="activate">Activeren</button>
              <button class="btn small quiet" type="submit" name="action" value="deactivate" data-confirm-button="Deze plugin deactiveren op de gekozen sites?">Deactiveren</button>
              <button class="btn small danger" type="submit" name="action" value="delete" data-confirm-button="Deze plugin verwijderen van de gekozen sites? Dit kan niet ongedaan worden gemaakt (behalve met een backup).">Verwijderen</button>
            </div>
            <p class="note">Verwijderen kan alleen als de plugin op die site niet actief is: deactiveer eerst. Elke wijziging komt in het wijzigingslog van de site.</p>
          </div>
        </section>

        <section class="panel">
          <h3>Onderhoudsmodus</h3>
          <div class="stack mt">
            <label>Bericht voor bezoekers<textarea name="message" rows="2" maxlength="500" placeholder="We voeren onderhoud uit aan deze website. Probeer het straks opnieuw.">{{ old('message') }}</textarea></label>
            <label>Gaat vanzelf uit na
              <select name="hours">
                @foreach ([1 => '1 uur', 2 => '2 uur', 4 => '4 uur', 8 => '8 uur', 24 => '1 dag', 72 => '3 dagen', 168 => '7 dagen'] as $h => $label)
                  <option value="{{ $h }}" @selected((int) old('hours', 4) === $h)>{{ $label }}</option>
                @endforeach
              </select>
            </label>
            <div class="row-actions">
              <button class="btn small danger" type="submit" name="action" value="maintenance_on" data-confirm-button="Onderhoudsmodus aanzetten? Bezoekers zien de gekozen sites dan niet.">Aanzetten</button>
              <button class="btn small quiet" type="submit" name="action" value="maintenance_off">Uitzetten</button>
            </div>
            <p class="note">Bezoekers krijgen een 503 met dit bericht; ingelogde beheerders, WP-admin en het panel werken gewoon.</p>
          </div>
        </section>
      </div>
    </form>
  @else
    <div class="alert warn"><p>Alleen beheerders kunnen bulk-acties uitvoeren.</p></div>
  @endcan

  <section class="panel">
    <div class="panel-head"><h3>Recente opdrachten</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tijd</th><th>Site</th><th>Opdracht</th><th>Door</th><th>Status</th></tr></thead>
        <tbody>
          @forelse ($jobs as $job)
            <tr>
              <td>{{ $job->created_at->timezone('Europe/Amsterdam')->format('d-m H:i') }}</td>
              <td>@if ($job->site)<a href="{{ route('sites.show', $job->site) }}">{{ $job->site->name }}</a>@endif</td>
              <td class="mono">{{ $job->command }} {{ \Illuminate\Support\Str::limit(json_encode($job->args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 70) }}</td>
              <td>{{ $job->requester?->name ?? 'systeem' }}</td>
              <td><span @class(['state', 'idle' => in_array($job->status, ['pending', 'running'], true)])>{{ $job->status }}</span>@if ($job->error) <span class="muted">{{ \Illuminate\Support\Str::limit($job->error, 80) }}</span>@endif</td>
            </tr>
          @empty
            <tr><td colspan="5" class="muted">Nog geen bulk-opdrachten.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
@endsection
