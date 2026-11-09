{{-- Risicolabel van een update (UpdateRisk). Laag zonder redenen = geen label; de redenen staan in de tooltip. --}}
@if ($risk && ($risk['level'] !== 'low' || $risk['reasons'] !== []))
  <span class="badge risk-{{ $risk['level'] }}" title="{{ \App\Ocm\UpdateRisk::tooltip($risk) }}">{{ \App\Ocm\UpdateRisk::LABELS[$risk['level']] }}</span>
@endif
