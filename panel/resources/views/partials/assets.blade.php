{{-- CSS/JS met ?v=<mtime>, zodat browsers en proxies na een deploy direct de nieuwe versie laden. --}}
@php($asset = fn (string $path) => '/' . $path . (is_file(public_path($path)) ? '?v=' . filemtime(public_path($path)) : ''))
  <script src="{{ $asset('js/theme.js') }}"></script>
  <link rel="stylesheet" href="{{ $asset('css/ds/styles.css') }}">
  <link rel="stylesheet" href="{{ $asset('css/serverdash.css') }}">
  <link rel="stylesheet" href="{{ $asset('css/ocm.css') }}">
  <script src="{{ $asset('js/app.js') }}" defer></script>
