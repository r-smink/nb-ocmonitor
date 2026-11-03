@extends('layouts.app')
@section('title', 'Versies')
@section('content')
  <div class="page-head">
    <div>
      <h1>Versies</h1>
      <p>PHP, WordPress en de client-plugin per site. Rood: geen beveiligingsupdates meer. Oranje: binnen 6 maanden, of niet de nieuwste WordPress{{ $latestWp ? ' (' . $latestWp . ')' : '' }}.</p>
    </div>
  </div>
  @include('partials.tag-filter')

  <div class="cols">
    <section class="panel">
      <div class="panel-head"><h3>PHP</h3></div>
      <div class="dist">
        @foreach ($phpCounts as $version => $count)
          @php($eol = $version !== 'onbekend' ? \App\Ocm\SiteIssues::phpEol((string) $version) : null)
          <span @class(['chip', 'crit has' => $eol && now()->gt($eol), 'warn has' => $eol && !now()->gt($eol) && now()->addMonths(6)->gt($eol)])>PHP {{ $version }}: {{ $count }}</span>
        @endforeach
      </div>
    </section>
    <section class="panel">
      <div class="panel-head"><h3>WordPress</h3></div>
      <div class="dist">
        @foreach ($wpCounts as $version => $count)<span class="chip">WordPress {{ $version }}: {{ $count }}</span>@endforeach
      </div>
    </section>
  </div>

  <section class="panel">
    <div class="panel-head"><h3>Per site</h3><span class="badge">{{ $rows->count() }}</span></div>
    <div class="table-wrap scroll-lg">
      <table>
        <thead><tr><th>Site</th><th>PHP</th><th>WordPress</th><th>Database</th><th>OC-plugin</th><th class="num">Plugins</th><th class="num">Inactief</th><th class="num">Updates</th><th>Thema</th><th class="num">DB-grootte</th></tr></thead>
        <tbody>
          @forelse ($rows as $r)
            <tr data-href="{{ route('sites.show', $r['site']) }}">
              <td><a href="{{ route('sites.show', $r['site']) }}">{{ $r['site']->name }}</a> @include('partials.tags', ['tags' => $r['site']->allTags()])</td>
              <td><span @class(['ver', $r['php_state'] => in_array($r['php_state'], ['crit', 'warn'], true)]) title="{{ $r['php_eol'] ? 'Beveiligingsupdates tot ' . \Carbon\Carbon::parse($r['php_eol'])->format('d-m-Y') : '' }}">{{ $r['php'] ?? '–' }}</span></td>
              <td><span @class(['ver', 'warn' => $r['wp_outdated']])>{{ $r['wp'] ?? '–' }}</span></td>
              <td class="muted">{{ $r['db'] ?? '–' }}</td>
              <td class="ver">{{ $r['plugin'] ?? '–' }}</td>
              <td class="num">{{ $r['plugins'] }}</td>
              <td class="num">{{ $r['inactive'] ?: '' }}</td>
              <td class="num">{{ $r['updates'] ?: '' }}</td>
              <td class="wrap">{{ $r['theme'] ?? '–' }}</td>
              <td class="num">{{ $r['db_mb'] !== null ? number_format($r['db_mb'], 1, ',', '.') . ' MB' : '–' }}</td>
            </tr>
          @empty
            <tr><td colspan="10" class="muted">Geen gekoppelde sites.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
@endsection
