<div class="form-row">
  <label>Naam<input name="name" value="{{ $product?->name }}" maxlength="80" @if ($product) required @endif placeholder="WP Rocket"></label>
  <label>Pluginbestand<input name="plugin_file" value="{{ $product?->plugin_file }}" maxlength="190" @if ($product) required @endif placeholder="wp-rocket/wp-rocket.php" spellcheck="false"></label>
</div>
<div class="form-row">
  <label>Activeren
    <select name="method">
      @foreach (\App\Models\LicenseProduct::METHODS as $method => $label)<option value="{{ $method }}" @selected(($product?->method ?? 'manual') === $method)>{{ $label }}</option>@endforeach
    </select>
  </label>
  <label>Optienaam (alleen bij "sleutel in een optie")<input name="option_name" value="{{ $product?->option_name }}" maxlength="160" placeholder="bijv. wp_rocket_license" spellcheck="false"></label>
</div>
<label>Instellingenpagina in WP-admin (optioneel)<input name="settings_path" value="{{ $product?->settings_path }}" maxlength="190" placeholder="admin.php?page=..." spellcheck="false"></label>
