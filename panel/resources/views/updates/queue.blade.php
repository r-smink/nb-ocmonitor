@extends('layouts.app')
@section('title', 'Update-wachtrij')
@if ($open)
  @section('refresh', '10')
@endif
@section('content')
  @php
    $phaseClass = ['backup_wait' => 'wait', 'backup' => 'busy', 'screenshot' => 'busy', 'held' => 'wait', 'queued' => 'wait', 'running' => 'busy', 'done' => 'ok', 'partial' => 'bad', 'failed' => 'bad'];
    $statusLabels = ['updated' => 'bijgewerkt', 'up_to_date' => 'al actueel', 'failed' => 'mislukt', 'rolled_back' => 'teruggedraaid', 'health_failed' => 'site-fout na update', 'not_found' => 'niet gevonden'];
    $bar = function (array $c): string {
        if ($c['total'] === 0) {
            return '';
        }
        $html = '';
        foreach (['ok', 'bad', 'running', 'waiting'] as $part) {
            if ($c[$part] > 0) {
                $html .= '<i class="' . $part . '" style="width:' . round($c[$part] * 100 / $c['total'], 2) . '%"></i>';
            }
        }

        return '<span class="qbar" role="img" aria-label="' . $c['ok'] . ' klaar, ' . $c['bad'] . ' mislukt, ' . $c['running'] . ' bezig, ' . $c['waiting'] . ' wachtend">' . $html . '</span>';
    };
  @endphp
  <div class="page-head">
    <div>
      <h1>Updates</h1>
      <p>Wat er nu loopt en wat de afgelopen {{ $hours }} uur klaar is, per klant en per site. Ververst vanzelf zolang er iets bezig is.</p>
    </div>
  </div>
  @include('updates.tabs')

  <div class="toolbar">
    <form class="filters" method="GET" action="{{ route('updates.queue') }}">
      <select name="klant" data-autosubmit aria-label="Klant">
        <option value="">Alle klanten</option>
        @foreach ($customers as $c)
          <option value="{{ $c->id }}" @selected($customerId === $c->id)>{{ $c->name }}</option>
        @endforeach
      </select>
      <select name="uren" data-autosubmit aria-label="Periode">
        @foreach ([6 => 'Afgelopen 6 uur', 24 => 'Afgelopen 24 uur', 72 => 'Afgelopen 3 dagen', 168 => 'Afgelopen week'] as $h => $label)
          <option value="{{ $h }}" @selected($hours === $h)>{{ $label }}</option>
        @endforeach
      </select>
    </form>
  </div>

  <section class="panel">
    <div class="panel-head">
      <h3>Totaal</h3>
      @if ($open)<span class="state idle">{{ $totals['open'] }} opdracht(en) open</span>@elseif ($totals['jobs'] > 0)<span class="state">alles afgerond</span>@endif
      <span class="right hint">{{ $totals['sites'] }} site(s) · {{ $totals['customers'] }} klant(en) · {{ $totals['jobs'] }} opdracht(en)</span>
    </div>
    @if ($totals['jobs'] === 0)
      <p class="muted">Geen updates in de wachtrij en niets uitgevoerd in deze periode.</p>
    @else
      <div class="q-total">
        <div class="q-percent">{{ $totals['percent'] }}%</div>
        <div class="q-total-bar">
          {!! $bar($totals) !!}
          <div class="q-legend">
            <span class="ok">{{ $totals['ok'] }} klaar</span>
            <span class="bad">{{ $totals['bad'] }} mislukt</span>
            <span class="running">{{ $totals['running'] }} bezig</span>
            <span class="waiting">{{ $totals['waiting'] }} wachtend</span>
            <span class="muted">van {{ $totals['total'] }} component(en)</span>
          </div>
        </div>
      </div>
      <div class="q-phases">
        @foreach (\App\Ocm\UpdateQueue::PHASES as $key => $label)
          @if ($totals['phase_' . $key] > 0)
            <span class="q-phase {{ $phaseClass[$key] }}">{{ $label }} <b>{{ $totals['phase_' . $key] }}</b></span>
          @endif
        @endforeach
      </div>
    @endif
  </section>

  @foreach ($groups as $group)
    <section class="panel">
      <div class="panel-head">
        <h3>@if ($group['customer'])<a href="{{ route('customers.show', $group['customer']) }}">{{ $group['customer']->name }}</a>@else Sites zonder klant @endif</h3>
        @if ($group['open'] > 0)<span class="state idle">bezig</span>@elseif ($group['bad'] > 0)<span class="state bad">{{ $group['bad'] }} mislukt</span>@else<span class="state">klaar</span>@endif
        <div class="right q-head-bar">
          {!! $bar($group) !!}<span class="hint">{{ $group['ok'] + $group['bad'] }}/{{ $group['total'] }} · {{ $group['percent'] }}%</span>
          @can('manage-sites')
            @if ($group['customer'] && $group['backups'] > 0 && $group['open'] === 0)
              <form method="POST" action="{{ route('customers.backup.clear', $group['customer']) }}" class="inline" data-confirm="Alles gecontroleerd bij {{ $group['customer']->name }}? Dan worden {{ $group['backups'] }} back-up(s) van de sites van deze klant direct verwijderd.">
                @csrf<button class="btn small quiet" type="submit">Alles werkt: {{ $group['backups'] }} back-up{{ $group['backups'] === 1 ? '' : 's' }} opruimen</button>
              </form>
            @endif
          @endcan
        </div>
      </div>

      @foreach ($group['sites'] as $entry)
        <div class="q-site">
          <div class="q-site-head">
            <a href="{{ route('sites.show', $entry['site']) }}"><b>{{ $entry['site']->name }}</b></a>
            <span class="muted">{{ $entry['site']->host }}</span>
            @if ($entry['visual'])
              @if ($entry['visual']->status === 'updating' && $entry['open'] === 0)
                <span class="q-phase busy">screenshot achteraf</span>
              @elseif ($entry['visual']->changed() && $entry['visual']->reviewed_at === null)
                <a class="q-phase bad" href="{{ route('sites.show', $entry['site']) }}#sd-updates">visueel {{ number_format($entry['visual']->diff_percent, 1, ',', '.') }}% veranderd — controleren</a>
              @elseif ($entry['visual']->status === 'done')
                <span class="q-phase ok">visueel in orde</span>
              @endif
            @endif
            <div class="right q-head-bar">
              {!! $bar($entry) !!}<span class="hint">{{ $entry['ok'] + $entry['bad'] }}/{{ $entry['total'] }}</span>
              @can('manage-sites')
                @if ($entry['backups'] > 0 && $entry['open'] === 0)
                  <form method="POST" action="{{ route('sites.backup.clear', $entry['site']) }}" class="inline" data-confirm="Alles gecontroleerd op {{ $entry['site']->name }}? Dan worden {{ $entry['backups'] }} back-up(s) direct verwijderd.">
                    @csrf<button class="btn small ghost" type="submit">{{ $entry['backups'] }} back-up{{ $entry['backups'] === 1 ? '' : 's' }} opruimen</button>
                  </form>
                @endif
              @endcan
            </div>
          </div>
          <div class="table-wrap">
            <table class="q-table">
              <tbody>
                @foreach ($entry['rows'] as $row)
                  <tr>
                    <td class="q-when">{{ $row['job']->created_at->timezone('Europe/Amsterdam')->format('d-m H:i') }}</td>
                    <td class="q-what"><b>{{ $row['label'] }}</b>@if ($row['total'] > 1) <span class="muted">({{ $row['total'] }})</span>@endif
                      <div class="hint">{{ $row['schedule'] ? 'automatisch: ' . $row['schedule'] : ($row['job']->requester?->name ?? 'systeem') }}</div>
                    </td>
                    <td class="wrap">
                      <ul class="q-items">
                        @foreach ($row['items'] as $item)
                          <li class="{{ $item['state'] }}">
                            {{ $item['name'] }}
                            @if ($item['status'] === 'updated' && $item['from'])<span class="muted">{{ $item['from'] }} → {{ $item['to'] }}</span>@elseif ($item['status'])<span class="muted">{{ $statusLabels[$item['status']] ?? $item['status'] }}</span>@elseif ($item['to'])<span class="muted">→ {{ $item['to'] }}</span>@endif
                            @if ($item['message'] && $item['state'] === 'bad')<div class="note">{{ $item['message'] }}</div>@endif
                          </li>
                        @endforeach
                      </ul>
                      @if ($row['job']->error)<div class="note">{{ \Illuminate\Support\Str::limit($row['job']->error, 200) }}</div>@endif
                    </td>
                    <td class="q-state">
                      <span class="q-phase {{ $phaseClass[$row['phase']] }}">{{ \App\Ocm\UpdateQueue::PHASES[$row['phase']] }}</span>
                      @if ($row['phase'] === 'running' && $row['job']->started_at)<div class="hint">sinds {{ $row['job']->started_at->timezone('Europe/Amsterdam')->format('H:i') }}</div>
                      @elseif ($row['job']->finished_at)<div class="hint">{{ $row['job']->finished_at->timezone('Europe/Amsterdam')->format('H:i') }}</div>@endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      @endforeach
    </section>
  @endforeach

  <p class="note">Fases: eerst de backup vooraf (als die aan staat), dan de screenshot vooraf voor de visuele controle, daarna voert de site de updates één opdracht tegelijk uit. Na de laatste update volgt de screenshot achteraf.</p>
@endsection
