<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  {{-- Doorsturen binnen het panel: dat is een navigatie vanaf onze eigen site, dus het sessiecookie gaat weer mee. --}}
  <meta http-equiv="refresh" content="{{ $ok ? 1 : 6 }};url={{ route('settings.show') }}">
  <title>Google koppelen</title>
  <link rel="stylesheet" href="/css/ds/styles.css">
  <link rel="stylesheet" href="/css/serverdash.css">
  <link rel="stylesheet" href="/css/ocm.css">
</head>
<body>
  <main class="narrow mt">
    <section class="panel">
      <h3>Google koppelen</h3>
      <p @class(['mt', 'issue-text', 'crit' => !$ok])>{{ $message }}</p>
      <p class="mt"><a class="btn small" href="{{ route('settings.show') }}">Terug naar Instellingen</a></p>
    </section>
  </main>
</body>
</html>
