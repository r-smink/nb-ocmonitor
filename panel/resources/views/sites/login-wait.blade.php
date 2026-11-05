@extends('layouts.app')
@section('title', 'Inloggen op ' . $site->name)
@if (!$error)
  @section('refresh', '2')
@endif
@section('content')
  <section class="panel narrow">
    <h3>Inloggen op {{ $site->name }}</h3>
    @if ($error)
      <div class="alert warn mt">{{ $error }}</div>
      <p class="mt"><a class="btn ghost small" href="{{ route('sites.show', $site) }}#sd-gebruikers">Terug naar de site</a></p>
    @else
      <p class="mt"><span class="spinner" role="status"><span class="sr-only">Bezig</span></span> De site maakt een eenmalige inloglink. Je wordt zo doorgestuurd naar WP-admin…</p>
    @endif
  </section>
@endsection
