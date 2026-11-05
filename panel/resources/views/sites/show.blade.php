@extends('layouts.app')
@section('title', $site->name)
@section('main-class', 'main-wide')
@if ($jobs->whereIn('status', ['pending', 'running'])->isNotEmpty())
  @section('refresh', '5')
@elseif ($site->pagespeed_requested_at)
  @section('refresh', '15')
@endif
@section('content')
  @php($level = $site->level())
  @php($issues = collect($site->issues()))
  @php($paired = $site->isPaired())
  @php($showPsi = $paired && \App\Ocm\Menu::enabled('pagespeed'))
  @php($showUsers = $paired && \App\Ocm\Menu::enabled('wp-users'))
  @php($openUpdates = $updates->count())
  @php($openVulns = $vulns->whereIn('level', ['crit', 'warn'])->count())
  {{-- Secties van de pagina: sleutel => [label, icoon, badge, badgekleur]. De icoonbalk en de scrollspy volgen deze lijst. --}}
  @php($sections = array_filter([
      'status' => ['Status', 'grid', 0, null],
      'updates' => $paired ? ['Updates', 'refresh', $openUpdates, 'warn'] : null,
      'backups' => $paired ? ['Back-ups', 'clock', 0, null] : null,
      'beveiliging' => $paired ? ['Beveiliging', 'shield', $openVulns, 'crit'] : null,
      'schakelaars' => $paired ? ['Schakelaars', 'check', 0, null] : null,
      'pagespeed' => $showPsi ? ['PageSpeed', 'gauge', 0, null] : null,
      'links' => $paired && \App\Ocm\Menu::enabled('links') ? ['Kapotte links', 'filetext', \App\Models\BrokenLink::query()->where('site_id', $site->id)->whereNull('ignored_at')->count(), 'warn'] : null,
      'bezoekers' => $paired ? ['Bezoekers', 'activity', 0, null] : null,
      'gebruikers' => $showUsers ? ['Gebruikers', 'users', 0, null] : null,
      'activiteit' => ['Activiteit', 'activity', 0, null],
  ]))

  <div class="sd-layout">
    <nav class="sd-rail" aria-label="Secties van deze pagina" data-scrollspy>
      @foreach ($sections as $key => [$label, $icon, $count, $countLevel])
        <a href="#sd-{{ $key }}" class="sd-rail-item" data-spy-link="{{ $key }}" aria-label="{{ $label }}{{ $count > 0 ? ' (' . $count . ')' : '' }}">
          @include('partials.icon', ['name' => $icon, 'size' => 20])
          @if ($count > 0)<span class="sd-rail-count {{ $countLevel }}" aria-hidden="true">{{ $count > 99 ? '99+' : $count }}</span>@endif
          <span class="sd-rail-label" aria-hidden="true">{{ $label }}</span>
        </a>
      @endforeach
    </nav>

    <div class="sd-content">
      <div class="sd-crumb"><a href="{{ route('sites.index') }}">← Sites</a><span>/ {{ $site->name }}</span></div>

      @include('sites.show.status')
      @if ($paired)
        @include('sites.show.updates')
        @include('sites.show.backups')
        @include('sites.show.security')
      @endif
      @if ($paired)
        @include('sites.show.tweaks')
      @endif
      @if ($showPsi)
        @include('sites.show.pagespeed')
      @endif
      @if ($paired && \App\Ocm\Menu::enabled('links'))
        @include('sites.show.links')
      @endif
      @if ($paired)
        @include('sites.show.analytics')
      @endif
      @if ($showUsers)
        @include('sites.show.users')
      @endif
      @include('sites.show.activity')
    </div>
  </div>
@endsection
