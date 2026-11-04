{{-- Voorkeuren voor meldingen. $prefs = AlertFilter::normalize(); $quiet = stille uren tonen (alleen gebruikers); $enabledLabel. --}}
@php($allTags = $allTags ?? \App\Models\Tag::query()->orderBy('name')->get())
@php($allCustomers = $allCustomers ?? \App\Models\Customer::query()->orderBy('name')->get(['id', 'name']))
<div class="stack">
  <label class="check"><input type="checkbox" name="enabled" value="1" @checked($prefs['enabled'])> {{ $enabledLabel ?? 'Meldingen ontvangen' }}</label>
  <div class="form-row">
    <label>Niveau
      <select name="min_level">
        @foreach (\App\Ocm\Alerts\AlertFilter::LEVELS as $value => $label)
          <option value="{{ $value }}" @selected($prefs['min_level'] === $value)>{{ $label }}</option>
        @endforeach
      </select>
    </label>
    <label class="check self-end"><input type="checkbox" name="resolved" value="1" @checked($prefs['resolved'])> Ook melden als het is opgelost</label>
  </div>

  <div>
    <span class="field-label">Categorieën <span class="hint">(niets aangevinkt = alle)</span></span>
    <div class="check-grid">
      @foreach (\App\Ocm\SiteIssues::CATEGORIES as $key => $label)
        <label><input type="checkbox" name="categories[]" value="{{ $key }}" @checked(in_array($key, $prefs['categories'], true))> {{ $label }}</label>
      @endforeach
    </div>
  </div>

  @if ($allTags->isNotEmpty() || $allCustomers->isNotEmpty())
    <div>
      <span class="field-label">Alleen deze sites <span class="hint">(niets aangevinkt = alle sites; een site hoeft maar bij één tag of klant te horen)</span></span>
      <div class="check-grid">
        @foreach ($allTags as $tag)
          <label><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $prefs['tags'], true))> <span class="tag {{ $tag->color }}">{{ $tag->name }}</span></label>
        @endforeach
        @foreach ($allCustomers as $customer)
          <label><input type="checkbox" name="customers[]" value="{{ $customer->id }}" @checked(in_array($customer->id, $prefs['customers'], true))> {{ $customer->name }}</label>
        @endforeach
      </div>
    </div>
  @endif

  @if ($quiet ?? false)
    <div class="form-row">
      <label>Stille uren van<input type="time" name="quiet_from" value="{{ $prefs['quiet_from'] }}"></label>
      <label>tot<input type="time" name="quiet_to" value="{{ $prefs['quiet_to'] }}"></label>
      <label class="check self-end"><input type="checkbox" name="quiet_crit" value="1" @checked($prefs['quiet_crit'])> Kritiek komt wel door</label>
    </div>
    <p class="note">Tijden in Nederlandse tijd. Wat in de stille uren binnenkomt, krijg je daarna in één bericht.</p>
  @endif
</div>
