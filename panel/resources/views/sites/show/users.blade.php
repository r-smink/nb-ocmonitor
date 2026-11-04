{{-- Sectie 6: WordPress-gebruikers van de site, met 2FA-beleid en acties per gebruiker. --}}
<section class="sd-section" id="sd-gebruikers" data-spy="gebruikers">
  <div class="panel">
    <div class="panel-head">
      <span class="sd-ic">@include('partials.icon', ['name' => 'users', 'size' => 18])</span>
      <h3>WordPress-gebruikers</h3><span class="count-pill">{{ $wpUsers->count() }}</span>
      @if ($site->wp_users_at)<span class="hint">opgehaald {{ $site->wp_users_at->diffForHumans() }}</span>@endif
      @can('manage-sites')
        @if ($site->usersBlockedReason() === null)
          <div class="right">
            <form method="POST" action="{{ route('sites.two-factor', $site) }}" class="row-actions">
              @csrf
              <label class="hint" for="tfa-mode">2FA afdwingen</label>
              <select id="tfa-mode" name="mode" class="w-auto">
                @foreach (\App\Http\Controllers\WpUserController::TWO_FACTOR_MODES as $mode => $label)<option value="{{ $mode }}" @selected((($site->wp_users ?? [])['two_factor_mode'] ?? 'off') === $mode)>{{ $label }}</option>@endforeach
              </select>
              <button class="btn ghost small" type="submit">Opslaan</button>
            </form>
          </div>
        @endif
      @endcan
    </div>
    @if ($reason = $site->usersBlockedReason())
      <p class="muted">{{ $reason }}.</p>
    @else
      @if (!empty(($site->wp_users ?? [])['two_factor_disabled']))<p class="note">2FA staat op de site uit via NB_OCM_DISABLE_2FA in wp-config.php.</p>@endif
      @include('partials.wp-users-table', ['rows' => $wpUsers, 'showSite' => false])
    @endif
  </div>
</section>
