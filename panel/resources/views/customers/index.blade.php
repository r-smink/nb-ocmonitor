@extends('layouts.app')
@section('title', 'Klanten')
@section('content')
  <div class="page-head">
    <div>
      <h1>Klanten</h1>
      <p>Klanten met hun onderhoudscontract, sites en rapport. De planning staat onder <a href="{{ route('maintenance.index') }}">Onderhoud</a>.</p>
    </div>
    @can('manage-sites')
      <div class="right row-actions"><a class="btn ghost" href="{{ route('tags.index') }}">Tags</a><a class="btn" href="{{ route('customers.create') }}">Klant toevoegen</a></div>
    @endcan
  </div>

  <section class="panel">
    <div class="panel-head"><h3>Alle klanten</h3><span class="badge">{{ $customers->count() }}</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Klant</th><th>Contract</th><th>Volgend onderhoud</th><th>Sites</th><th>Rapport</th>@can('manage-sites')<th></th>@endcan</tr></thead>
        <tbody>
          @forelse ($customers as $customer)
            @php($pending = $customer->sites->filter(fn ($s) => $s->isPaired() && $s->updatesBlockedReason() === null)->sum(fn ($s) => collect($s->availableUpdates())->whereIn('type', ['plugin', 'theme'])->count()))
            <tr data-href="{{ route('customers.show', $customer) }}">
              <td>
                <a href="{{ route('customers.show', $customer) }}"><b>{{ $customer->name }}</b></a> @include('partials.tags', ['tags' => $customer->tags])
                <div class="note">{{ collect([$customer->contact_name, $customer->email])->filter()->implode(' · ') ?: '–' }}</div>
              </td>
              <td>{{ $customer->contractLabel() }}<div class="note">{{ $customer->contract_start ? 'sinds ' . $customer->contract_start->format('d-m-Y') : '' }}</div></td>
              <td>
                @if ($date = $next[$customer->id])
                  <span @class(['issue-text', 'crit' => $date->lessThan($today)])>{{ $date->format('d-m-Y') }}</span>
                  @if ($date->lessThan($today))<div class="note">achterstallig</div>@elseif ($date->isSameDay($today))<div class="note">vandaag</div>@endif
                @else
                  <span class="muted">–</span>
                @endif
              </td>
              <td class="wrap">{{ $customer->sites->pluck('name')->implode(', ') ?: '–' }}</td>
              <td>@if ($customer->report_enabled)<span class="state">rapport aan</span>@else<span class="state idle">geen rapport</span>@endif</td>
              @can('manage-sites')
                <td class="actions">
                  @if ($pending > 0)
                    <form method="POST" action="{{ route('customers.updates', $customer) }}" class="row-form" data-confirm="Alle beschikbare updates starten op de sites van {{ $customer->name }}?">
                      @csrf
                      <button class="btn small quiet" type="submit">Updates starten ({{ $pending }})</button>
                    </form>
                  @endif
                </td>
              @endcan
            </tr>
          @empty
            <tr><td colspan="6" class="muted">Nog geen klanten.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
@endsection
