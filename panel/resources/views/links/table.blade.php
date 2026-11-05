<div class="table-wrap scroll-lg">
  <table>
    <thead><tr>@if ($showSite)<th>Site</th>@endif<th>Link</th><th>Fout</th><th>Gevonden op</th><th>Sinds</th>@can('manage-sites')<th></th>@endcan</tr></thead>
    <tbody>
      @forelse ($links as $link)
        <tr @class(['muted' => $link->ignored_at])>
          @if ($showSite)<td><a href="{{ route('sites.show', $link->site) }}#sd-links">{{ $link->site->name }}</a></td>@endif
          <td class="wrap"><span class="badge">{{ $link->kind === 'image' ? 'afbeelding' : ($link->internal ? 'intern' : 'extern') }}</span> <a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer nofollow" class="mono">{{ \Illuminate\Support\Str::limit($link->url, 90) }}</a></td>
          <td><span class="state bad" title="{{ $link->error }}">{{ $link->label() }}</span></td>
          <td class="wrap">
            @foreach (array_slice($link->found_on, 0, 3) as $page)<a href="{{ $page }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit(parse_url($page, PHP_URL_PATH) ?: '/', 50) }}</a>@if (!$loop->last), @endif @endforeach
            @if (count($link->found_on) > 3)<span class="muted">+{{ count($link->found_on) - 3 }}</span>@endif
          </td>
          <td>{{ $link->first_seen_at->timezone('Europe/Amsterdam')->format('d-m-Y') }}</td>
          @can('manage-sites')
            <td class="num"><form method="POST" action="{{ route('links.ignore', $link) }}" class="inline">@csrf<button class="btn ghost small" type="submit">{{ $link->ignored_at ? 'Weer melden' : 'Negeren' }}</button></form></td>
          @endcan
        </tr>
      @empty
        <tr><td colspan="6" class="muted">Geen kapotte links gevonden.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
