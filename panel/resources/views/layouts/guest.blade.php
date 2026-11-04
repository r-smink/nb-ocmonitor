<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('title', 'Inloggen') — OC Monitor</title>
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  @include('partials.assets')
</head>
<body class="login-page">
  <div class="login-box">
    <div class="brand"><span class="logo" role="img" aria-label="Next Buzz"></span>OC Monitor</div>
    @yield('content')
    <p class="foot">Next Buzz · OC Monitor</p>
  </div>
</body>
</html>
