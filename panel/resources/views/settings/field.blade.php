@php([$label, $type, $path, $min, $max] = \App\Ocm\AppSettings::FIELDS[$key])
@php($stored = $values[$key] ?? null)
@php($fallback = $path ? config($path) : null)
@if ($type === 'bool')
  <label>{{ $label }}
    <select name="{{ $key }}">
      <option value="" @selected($stored === null)>Standaard ({{ $fallback ? 'aan' : 'uit' }})</option>
      <option value="1" @selected($stored === true)>Aan</option>
      <option value="0" @selected($stored === false)>Uit</option>
    </select>
  </label>
@elseif ($type === 'secret')
  <label>{{ $label }}
    <input type="password" name="{{ $key }}" autocomplete="off" spellcheck="false" placeholder="{{ \App\Ocm\AppSettings::hasSecret($key) ? 'opgeslagen, laat leeg om te houden' : ($fallback ? 'uit .env' : 'niet ingesteld') }}">
    @if (\App\Ocm\AppSettings::hasSecret($key))<span class="hint"><input type="checkbox" name="clear[]" value="{{ $key }}"> verwijderen (dan geldt .env weer)</span>@endif
  </label>
@else
  <label>{{ $label }}
    <input @if ($type === 'text') type="text" maxlength="{{ $max }}" @else type="number" min="{{ $min }}" max="{{ $max }}" step="{{ $type === 'float' ? '0.5' : '1' }}" @endif name="{{ $key }}" value="{{ $stored }}" placeholder="{{ $key === 'app_name' ? 'OC Monitor' : $fallback }}">
  </label>
@endif
