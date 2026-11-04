@extends('layouts.app')
@section('title', 'Meldingen')
@section('content')
  <div class="page-head">
    <div>
      <h1>Instellingen</h1>
      <p>Meldingen bij nieuwe en opgeloste problemen. Elk incident wordt één keer gemeld, pas als het {{ (int) config('ocm.alerts.delay_minutes') }} minuten open staat (in te stellen onder Algemeen).</p>
    </div>
    @unless (\App\Ocm\Alerts\Alerts::enabled())
      <div class="right summary"><span class="chip warn has">Versturen staat uit</span></div>
    @endunless
  </div>
  @include('settings.tabs')

  <div class="cols cols-wide">
    <section class="panel">
      <div class="panel-head"><h3>Portalgebruikers</h3><span class="hint">Per e-mail. Iedereen past dit zelf aan onder Account.</span></div>
      <div class="mini-list">
        @foreach ($users as $user)
          @php($filter = \App\Ocm\Alerts\AlertFilter::forUser($user))
          <div class="mini-row">
            <div class="grow">
              <span class="name">{{ $user->name }}</span>
              <span class="sub">{{ $user->email }} · {{ $filter->summary() }}</span>
            </div>
            <a class="btn small quiet" href="{{ route('users.alerts', $user) }}">Aanpassen</a>
          </div>
        @endforeach
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h3>Nu kritiek</h3><span class="count-pill @if ($open->isNotEmpty()) crit @endif">{{ $open->count() }}</span></div>
      <div class="mini-list">
        @forelse ($open as $alert)
          <div class="mini-row">
            <div class="grow">
              <a class="name" href="{{ route('sites.show', $alert->site) }}">{{ $alert->site->name }}</a>
              <span class="sub">{{ $alert->text }} · sinds {{ $alert->opened_at->diffForHumans() }}</span>
            </div>
          </div>
        @empty
          <p class="muted">Geen kritieke incidenten.</p>
        @endforelse
      </div>
    </section>
  </div>

  @foreach ($channels as $type => $channel)
    <section class="panel" id="kanaal-{{ $type }}">
      <div class="channel-head">
        <h3>{{ $channel['label'] }}</h3>
        <span @class(['state', 'idle' => !$channel['configured']])>{{ $channel['configured'] ? 'ingesteld' : 'niet ingesteld' }}</span>
        @if ($channel['configured'])
          <div class="right">
            <form method="POST" action="{{ route('settings.alerts.test', $type) }}" class="inline">
              @csrf
              <button class="btn ghost small" type="submit">Testbericht</button>
            </form>
          </div>
        @endif
      </div>
      <form method="POST" action="{{ route('settings.alerts.channel', $type) }}" class="form mt">
        @csrf
        @method('PUT')
        <label>Webhook-URL
          <input type="password" name="url" autocomplete="off" spellcheck="false" maxlength="1000"
            placeholder="{{ $channel['configured'] ? 'opgeslagen, laat leeg om te houden' : match ($type) { 'slack' => 'https://hooks.slack.com/services/…', 'teams' => 'https://…logic.azure.com/workflows/…', default => 'https://…' } }}">
        </label>
        @if ($type === 'teams')
          <p class="note">In Teams: Workflows → "Post to a channel when a webhook request is received" en plak de URL hier.</p>
        @elseif ($type === 'webhook')
          <p class="note">POST met JSON <code>{"event":"ocm.alerts","items":[…]}</code>. Controleer <code>X-OCM-Signature</code> = <code>sha256=</code>HMAC-SHA256 van <code>X-OCM-Timestamp + "." + body</code>.
            @if ($secret) Geheim: <code class="mono">{{ $secret }}</code>@endif</p>
        @endif
        @include('partials.alert-prefs', ['prefs' => $channel['prefs'], 'quiet' => false, 'enabledLabel' => 'Dit kanaal gebruiken'])
        <div class="actions">
          <button class="btn" type="submit">Opslaan</button>
          @if ($channel['configured'])
            <label class="check"><input type="checkbox" name="clear" value="1"> Kanaal verwijderen</label>
          @endif
        </div>
      </form>
    </section>
  @endforeach

  <section class="panel">
    <div class="panel-head"><h3>Laatst verstuurd</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tijd</th><th>Ontvanger</th><th>Soort</th><th>Site</th><th>Melding</th><th>Status</th></tr></thead>
        <tbody>
          @php($names = $users->keyBy(fn ($u) => 'user:' . $u->id)->map->name)
          @forelse ($deliveries as $delivery)
            <tr>
              <td>{{ $delivery->created_at->timezone('Europe/Amsterdam')->format('d-m H:i') }}</td>
              <td>{{ $names[$delivery->recipient] ?? (\App\Ocm\Alerts\Channels::TYPES[\Illuminate\Support\Str::after($delivery->recipient, 'channel:')] ?? $delivery->recipient) }}</td>
              <td>{{ \App\Ocm\Alerts\Message::KINDS[$delivery->kind] ?? $delivery->kind }}</td>
              <td>@if ($delivery->alert?->site)<a href="{{ route('sites.show', $delivery->alert->site) }}">{{ $delivery->alert->site->name }}</a>@endif</td>
              <td>{{ \Illuminate\Support\Str::limit($delivery->alert?->text, 80) }}</td>
              <td>@if ($delivery->sent_at)<span class="state">verstuurd</span>@else<span class="delivery-error">{{ $delivery->error }}</span>@endif</td>
            </tr>
          @empty
            <tr><td colspan="6" class="muted">Nog niets verstuurd.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
@endsection
