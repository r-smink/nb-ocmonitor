@extends('layouts.app')
@section('title', 'Activiteit')
@section('content')
  <div class="page-head">
    <div>
      <h1>Activiteit</h1>
      <p>Wijzigingen op alle sites, en het audit log van het panel.</p>
    </div>
    <div class="right">
      <nav class="tabs-nav">
        <a href="{{ route('activity.index') }}" @class(['on' => $tab === 'sites'])>Wijzigingen op sites</a>
        <a href="{{ route('activity.index', ['tab' => 'audit']) }}" @class(['on' => $tab === 'audit'])>Audit log panel</a>
      </nav>
    </div>
  </div>

  <div class="panel">
    @if ($tab === 'sites')
      @include('partials.events-table', ['events' => $events, 'showSite' => true])
      {{ $events->withQueryString()->links() }}
    @else
      <div class="table-wrap">
        <table>
          <thead><tr><th>Tijd</th><th>Actie</th><th>Gebruiker</th><th>Site</th><th>Details</th><th>IP</th></tr></thead>
          <tbody>
            @forelse ($audit as $row)
              <tr>
                <td>{{ $row->created_at->timezone('Europe/Amsterdam')->format('d-m-Y H:i:s') }}</td>
                <td class="mono">{{ $row->action }}</td>
                <td>{{ $row->user?->name ?? '–' }}</td>
                <td>@if ($row->site)<a href="{{ route('sites.show', $row->site) }}">{{ $row->site->name }}</a>@else – @endif</td>
                <td class="mono wrap">{{ $row->details ? \Illuminate\Support\Str::limit(json_encode($row->details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 160) : '' }}</td>
                <td class="mono">{{ $row->ip }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="muted">Nog geen activiteit.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      {{ $audit->withQueryString()->links() }}
      <p class="note mt">Elke regel bevat de hash van de vorige regel. Met <code>php artisan ocm:verify-log</code> controleer je of er achteraf iets is gewijzigd of verwijderd.</p>
    @endif
  </div>
@endsection
