@php($roleLabels = ['administrator' => 'beheerder', 'editor' => 'redacteur', 'shop_manager' => 'winkelmanager', 'author' => 'auteur', 'contributor' => 'schrijver', 'subscriber' => 'abonnee'])
@php($twoFactorLabels = ['email' => 'e-mailcode', 'two-factor' => 'Two Factor', 'wordfence' => 'Wordfence', 'wp-2fa' => 'WP 2FA'])
<div class="table-wrap scroll-lg">
  <table>
    <thead>
      <tr>
        @if ($showSite)<th>Site</th>@endif
        <th>Gebruiker</th><th>Rol</th><th>2FA</th><th>Laatst ingelogd</th><th class="num">Sessies</th>@can('manage-sites')<th></th>@endcan
      </tr>
    </thead>
    <tbody>
      @forelse ($rows as $u)
        <tr>
          @if ($showSite)<td><a href="{{ route('sites.show', $u['site']) }}">{{ $u['site']->name }}</a></td>@endif
          <td class="wrap"><b>{{ $u['login'] }}</b>@if ($u['locked']) <span class="badge sec">geblokkeerd</span>@endif<br><span class="muted">{{ $u['email'] }}</span></td>
          <td>{{ collect($u['roles'])->map(fn ($r) => $roleLabels[$r] ?? $r)->implode(', ') }}</td>
          <td>@if ($u['two_factor'])<span class="state">{{ $twoFactorLabels[$u['two_factor']] ?? $u['two_factor'] }}</span>@else<span class="state bad">geen</span>@endif</td>
          <td>{{ $u['last_login'] ? \Carbon\Carbon::createFromTimestamp($u['last_login'])->timezone('Europe/Amsterdam')->format('d-m-Y H:i') : '–' }}</td>
          <td class="num">{{ $u['sessions'] }}</td>
          @can('manage-sites')
            <td class="num">
              <form method="POST" action="{{ route('wp-users.action', [$u['site'], $u['id']]) }}" class="row-actions" data-confirm="Deze actie uitvoeren voor {{ $u['login'] }} op {{ $u['site']->name }}?">
                @csrf
                <select name="action" class="w-auto" aria-label="Actie voor {{ $u['login'] }}">
                  @foreach (\App\Http\Controllers\WpUserController::ACTIONS as $key => [$command, $label])
                    @continue($key === 'lock' && $u['locked'])
                    @continue($key === 'unlock' && !$u['locked'])
                    @continue($key === 'demote' && !array_intersect($u['roles'], ['administrator', 'editor', 'shop_manager']))
                    <option value="{{ $key }}">{{ $label }}</option>
                  @endforeach
                </select>
                <select name="role" class="w-auto" aria-label="Nieuwe rol (alleen bij rol verlagen)">
                  <option value="subscriber">→ abonnee</option>
                  <option value="editor">→ redacteur</option>
                  <option value="author">→ auteur</option>
                </select>
                <button class="btn ghost small" type="submit">Uitvoeren</button>
              </form>
              @can('manage-users')
                @if (!$u['locked'] && \App\Http\Controllers\PanelLoginController::blockedReason($u['site']) === null)
                  <form method="POST" action="{{ route('sites.login', $u['site']) }}" target="_blank" class="inline">
                    @csrf
                    <input type="hidden" name="user_id" value="{{ $u['id'] }}">
                    <button class="btn small" type="submit" title="Inloggen in WP-admin als {{ $u['login'] }} (eenmalige link)">Inloggen</button>
                  </form>
                @endif
              @endcan
            </td>
          @endcan
        </tr>
      @empty
        <tr><td colspan="7" class="muted">Geen gebruikers gevonden. Gegevens komen binnen zodra sites op client-plugin 0.4.0 of nieuwer draaien.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
