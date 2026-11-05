@extends('layouts.app')
@section('title', 'Kapotte links')
@section('content')
  <div class="page-head">
    <div>
      <h1>Kapotte links</h1>
      <p>Links en afbeeldingen die een fout geven, gevonden via de sitemap van elke site. Elke site wordt eens per {{ config('ocm.links.interval_days') }} dagen gecontroleerd, vanaf het panel.</p>
    </div>
    <div class="right">
      <a class="btn ghost small" href="{{ route('links.index', array_filter(['tag' => request('tag'), 'genegeerd' => $showIgnored ? null : 1])) }}">{{ $showIgnored ? 'Genegeerde verbergen' : 'Ook genegeerde tonen' }}</a>
    </div>
  </div>
  @include('partials.tag-filter')

  <section class="panel">
    <div class="panel-head"><h3>Per site</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Site</th><th>Laatste controle</th><th class="num">Pagina's</th><th class="num">Links</th><th class="num">Kapot</th>@can('manage-sites')<th></th>@endcan</tr></thead>
        <tbody>
          @foreach ($sites as $site)
            @php($scan = $scans->get($site->id))
            <tr>
              <td><a href="{{ route('sites.show', $site) }}#sd-links">{{ $site->name }}</a></td>
              <td>{{ $scan?->finished_at?->diffForHumans() ?? 'nog niet' }}@if ($scan?->status === 'failed') <span class="state bad" title="{{ $scan->error }}">mislukt</span>@endif</td>
              <td class="num">{{ $scan?->pages ?? '' }}</td>
              <td class="num">{{ $scan?->links ?? '' }}</td>
              <td class="num">@if ($scan)<span @class(['issue-text', 'warn' => $scan->broken > 0])>{{ $scan->broken }}</span>@endif</td>
              @can('manage-sites')
                <td class="num">
                  @if ($site->links_requested_at)<span class="state idle">aangevraagd</span>@else
                    <form method="POST" action="{{ route('sites.links.request', $site) }}" class="inline">@csrf<button class="btn ghost small" type="submit">Nu controleren</button></form>
                  @endif
                </td>
              @endcan
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h3>Kapotte links</h3><span class="badge">{{ $links->count() }}</span></div>
    @include('links.table', ['links' => $links, 'showSite' => true])
  </section>
@endsection
