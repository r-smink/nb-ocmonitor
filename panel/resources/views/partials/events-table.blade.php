<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Tijd</th>
        @if ($showSite)<th>Site</th>@endif
        <th>Wijziging</th><th>Object</th><th>Van</th><th>Naar</th><th>Bron</th><th>Gebruiker</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($events as $event)
        <tr>
          <td>{{ $event->occurred_at->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}</td>
          @if ($showSite)<td><a href="{{ route('sites.show', $event->site) }}">{{ $event->site->name }}</a></td>@endif
          <td @class(['mono', 'res-failed' => $event->result === 'failed'])>{{ $event->type }}{{ $event->result === 'failed' ? ' (mislukt)' : '' }}</td>
          @if ($event->type === 'login.failed_summary')
            @php($channels = collect(($event->data ?? [])['channels'] ?? [])->sortDesc()->map(fn ($n, $c) => (['wp-login' => 'inlogpagina', 'xmlrpc' => 'XML-RPC', 'rest' => 'REST', 'other' => 'overig'][$c] ?? $c) . ' ' . $n)->implode(', '))
            <td class="wrap" colspan="3">{{ ($event->data ?? [])['count'] ?? 0 }} mislukte pogingen{{ $channels !== '' ? ' (' . $channels . ')' : '' }}{{ !empty(($event->data ?? [])['locked']) ? ', ' . $event->data['locked'] . 'x IP geblokkeerd' : '' }}</td>
          @else
          <td class="mono">{{ \Illuminate\Support\Str::limit($event->object, 60) }}</td>
          <td class="mono">{{ $event->from_version }}</td>
          <td class="mono">{{ $event->to_version }}</td>
          @endif
          <td><span @class(['src', 'panel' => $event->source === 'nb-ocm', 'extern' => $event->source === 'unknown'])>{{ $event->sourceLabel() }}</span></td>
          <td>{{ $event->user_login }}</td>
        </tr>
      @empty
        <tr><td colspan="{{ $showSite ? 8 : 7 }}" class="muted">Nog geen wijzigingen ontvangen.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
