@extends('layouts.app')
@section('title', 'API')
@section('content')
  <div class="page-head">
    <div>
      <h1>Instellingen</h1>
      <p>Tokens voor de alleen-lezen API, voor je eigen dashboards en koppelingen. De API geeft geen sleutels, koppelcodes of WordPress-gebruikers terug.</p>
    </div>
  </div>
  @include('settings.tabs')

  <section class="panel">
    <div class="channel-head">
      <h3>Eigen API</h3>
      <span @class(['state', 'idle' => !$enabled])>{{ $enabled ? 'aan' : 'uit' }}</span>
      <div class="right">
        <form method="POST" action="{{ route('settings.api.toggle') }}" class="inline" @if (!$enabled) data-confirm="De API aanzetten? Iedereen met een geldig token kan dan sitegegevens opvragen via /api/v1." @endif>
          @csrf
          <input type="hidden" name="enabled" value="{{ $enabled ? 0 : 1 }}">
          <button @class(['btn', 'small', 'quiet' => $enabled]) type="submit">{{ $enabled ? 'Uitzetten' : 'Aanzetten' }}</button>
        </form>
      </div>
    </div>
    <p class="note">{{ $enabled
      ? 'Verzoeken naar /api/v1 met een geldig token worden beantwoord. Let op: /api staat niet achter de IP-allowlist van het panel; alleen het token beschermt de API.'
      : 'Uit: elk verzoek naar /api/v1 krijgt een 404, ook met een geldig token. Tokens blijven bewaard en werken weer zodra je de API aanzet. De koppeling met de client-plugin op de sites werkt hier los van.' }}</p>
  </section>

  @if ($plain)
    <section class="panel">
      <div class="token-once">
        <h3>Nieuw token</h3>
        <code id="newToken" class="mono">{{ $plain }}</code>
        <div><button class="btn small" type="button" data-copy="#newToken">Kopieer token</button></div>
        <p class="note">Dit token zie je maar één keer. Bewaar het in een wachtwoordkluis of de secrets van je koppeling.</p>
      </div>
    </section>
  @endif

  <div class="cols cols-wide">
    <section class="panel">
      <div class="panel-head"><h3>Tokens</h3></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Naam</th><th>Token</th><th>Aangemaakt</th><th>Laatst gebruikt</th><th>Verloopt</th><th></th></tr></thead>
          <tbody>
            @forelse ($tokens as $token)
              <tr>
                <td>{{ $token->name }}<div class="muted">{{ $token->creator?->name }}</div></td>
                <td class="mono">{{ $token->prefix }}…</td>
                <td>{{ $token->created_at->timezone('Europe/Amsterdam')->format('d-m-Y') }}</td>
                <td>{{ $token->last_used_at ? $token->last_used_at->diffForHumans() . ' (' . $token->last_used_ip . ')' : 'nooit' }}</td>
                <td>{{ $token->expires_at?->timezone('Europe/Amsterdam')->format('d-m-Y') ?? 'nooit' }}</td>
                <td class="actions">
                  @if ($token->revoked_at)
                    <span class="state idle">ingetrokken</span>
                  @elseif (!$token->isActive())
                    <span class="state idle">verlopen</span>
                  @else
                    <form method="POST" action="{{ route('settings.api.destroy', $token) }}" class="inline" data-confirm="Token {{ $token->name }} intrekken? Koppelingen die het gebruiken stoppen direct.">
                      @csrf
                      @method('DELETE')
                      <button class="btn danger small" type="submit">Intrekken</button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="muted">Nog geen tokens.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <h3>Token aanmaken</h3>
      <form method="POST" action="{{ route('settings.api.store') }}" class="form mt">
        @csrf
        <label>Naam<input name="name" maxlength="80" required placeholder="bijv. Grafana-dashboard"></label>
        <label>Verloopt
          <select name="expires">
            <option value="90">over 90 dagen</option>
            <option value="30">over 30 dagen</option>
            <option value="365">over een jaar</option>
            <option value="">nooit</option>
          </select>
        </label>
        <div class="actions"><button class="btn" type="submit">Aanmaken</button></div>
      </form>
    </section>
  </div>

  <section class="panel">
    <h3>Gebruik</h3>
    <p class="note">Stuur het token mee als <code>Authorization: Bearer ocm_…</code>. Maximaal 120 verzoeken per minuut.</p>
    <dl class="kv mt">
      <dt class="mono">GET /api/v1/sites</dt><dd>Alle sites met status, niveau, versies, aantal updates en aandachtspunten. Filter: <code>?tag=webshop</code>.</dd>
      <dt class="mono">GET /api/v1/sites/{site_id}</dt><dd>Eén site, plus de aandachtspunten, beschikbare updates, SSL/domein en uptime (30 dagen).</dd>
      <dt class="mono">GET /api/v1/updates</dt><dd>Alle beschikbare updates over alle sites.</dd>
    </dl>
    <pre class="mono mt">curl -H "Authorization: Bearer ocm_…" {{ url('/api/v1/sites') }}</pre>
  </section>
@endsection
