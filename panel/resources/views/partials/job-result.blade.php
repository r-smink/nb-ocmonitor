{{-- Status van een job, met per component de uitkomst van een update. --}}
@php($updateLabels = ['updated' => 'bijgewerkt', 'up_to_date' => 'al actueel', 'failed' => 'mislukt', 'rolled_back' => 'teruggedraaid', 'health_failed' => 'site-fout na update', 'not_found' => 'niet gevonden'])
<span @class(['state', 'bad' => in_array($job->status, ['failed', 'rejected']), 'idle' => in_array($job->status, ['pending', 'running'])])>{{ ['pending' => 'in wachtrij', 'running' => 'bezig', 'done' => 'klaar', 'failed' => 'mislukt', 'rejected' => 'geweigerd'][$job->status] ?? $job->status }}</span>
@if ($job->error)<div class="note">{{ \Illuminate\Support\Str::limit($job->error, 160) }}</div>@endif
@if (str_starts_with($job->command, 'update.') && is_array($job->result))
  @if (!empty($job->result['items']))
    <ul class="job-items">
      @foreach ($job->result['items'] as $item)
        @continue(!is_array($item))
        <li @class(['bad' => in_array($item['status'] ?? '', ['failed', 'rolled_back', 'health_failed', 'not_found']), 'good' => ($item['status'] ?? '') === 'updated'])>
          <span class="mono">{{ $item['id'] ?? '' }}</span>
          {{ $updateLabels[$item['status'] ?? ''] ?? ($item['status'] ?? '') }}
          @if (($item['status'] ?? '') === 'updated') {{ $item['from'] ?? '' }} → {{ $item['to'] ?? '' }}@endif
          @if (!empty($item['message']) && ($item['status'] ?? '') !== 'up_to_date')<span class="muted">— {{ \Illuminate\Support\Str::limit($item['message'], 140) }}</span>@endif
        </li>
      @endforeach
    </ul>
  @elseif (isset($job->result['updated']))
    <div class="note">{{ $job->result['updated'] }} van {{ $job->result['total'] ?? $job->result['updated'] }} vertalingen bijgewerkt</div>
  @endif
@endif
