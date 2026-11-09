@php
  $on = fn (string $s) => in_array($s, $d['sections'], true);
  $date = fn ($v) => $v ? $v->timezone('Europe/Amsterdam')->format('d-m-Y') : '–';
  $scoreClass = fn ($v) => $v === null ? '' : ($v >= 90 ? 'score-ok' : ($v >= 50 ? 'score-warn' : 'score-crit'));
  $channelLabels = ['wp-login' => 'inlogpagina', 'xmlrpc' => 'XML-RPC', 'rest' => 'REST API', 'other' => 'overig'];
@endphp

@if ($on('summary'))
  <h2>Samenvatting</h2>
  <p>{{ $design['intro'] }}</p>
  @if (!empty($d['narrative']))<p>{{ $d['narrative'] }}</p>@endif
  @php $kpiV = 'font-family: ' . $design['font_heading'] . '; font-size: 22pt; font-weight: bold; color: ' . $design['color_primary'] . ';'; @endphp
  @php $kpiL = 'font-size: ' . max(7, $design['font_size'] - 2) . 'pt; color: ' . $design['color_muted'] . ';'; @endphp
  <table class="kpis">
    <tr>
      @php $kpis = [[$d['totals']['sites'], $d['totals']['sites'] === 1 ? 'website' : 'websites'], [$d['totals']['updates'], 'updates uitgevoerd'], [number_format($d['totals']['failed_logins'], 0, ',', '.'), 'inlogpogingen tegengehouden'], [$d['totals']['pending'], 'updates nog open']]; @endphp
      @foreach ($kpis as [$value, $label])
        <td><div style="{{ $kpiV }}">{{ $value }}</div><div style="{{ $kpiL }}">{{ $label }}</div></td>
      @endforeach
    </tr>
  </table>
@endif

@if ($on('maintenance') && $d['runs'] !== [])
  <h2>Onderhoudsmomenten</h2>
  <table class="data">
    <tr><th>Gepland</th><th>Uitgevoerd</th><th>Door</th><th>Notities</th></tr>
    @foreach ($d['runs'] as $run)
      <tr><td>{{ $date($run['date']) }}</td><td>{{ $run['done_at'] ? $date($run['done_at']) : 'nog niet' }}</td><td>{{ $run['by'] ?? '' }}</td><td>{!! nl2br(e($run['notes'] ?? '')) !!}</td></tr>
    @endforeach
  </table>
@endif

@foreach ($d['sites'] as $site)
  <div class="site-head">{{ $site['name'] }} <span>· {{ $site['url'] }}</span></div>

  @if ($on('uptime'))
    <h3>Beschikbaarheid</h3>
    @if (($site['uptime']['percent'] ?? null) === null)
      <p class="muted">Nog geen metingen in deze periode.</p>
    @else
      <table class="data">
        <tr><th>Uptime</th><th>Storingen</th><th>Totaal offline</th><th>Gem. reactietijd</th><th>SSL-certificaat geldig tot</th></tr>
        <tr>
          <td><b class="{{ $site['uptime']['percent'] >= 99.9 ? 'ok' : ($site['uptime']['percent'] >= 99 ? '' : 'bad') }}">{{ number_format($site['uptime']['percent'], 2, ',', '') }}%</b></td>
          <td>{{ $site['uptime']['incidents'] }}</td>
          <td>{{ $site['uptime']['down_minutes'] }} min</td>
          <td>{{ $site['uptime']['avg_ms'] !== null ? number_format($site['uptime']['avg_ms'] / 1000, 2, ',', '') . ' s' : '–' }}</td>
          <td>{{ $site['ssl_expires_at'] ? $site['ssl_expires_at']->format('d-m-Y') : '–' }}</td>
        </tr>
      </table>
      @if (!empty($site['uptime']['list']))
        <table class="data">
          <tr><th>Storing</th><th>Tot</th><th>Duur</th><th>Oorzaak</th></tr>
          @foreach ($site['uptime']['list'] as $incident)
            <tr>
              <td>{{ $incident['start']->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}</td>
              <td>{{ $incident['end'] ? $incident['end']->timezone('Europe/Amsterdam')->format('d-m-Y H:i') : 'nog offline' }}</td>
              <td>{{ $incident['duration'] }}</td>
              <td>{{ $incident['cause'] ?? '–' }}</td>
            </tr>
          @endforeach
        </table>
      @endif
      <p class="small muted">De homepage wordt elke 5 minuten gecontroleerd. Een storing telt vanaf twee mislukte controles op rij; de uptime is berekend over de tijd dat er gemeten werd.</p>
    @endif
  @endif

  @if ($on('updates'))
    <h3>Uitgevoerde updates</h3>
    @if ($site['updates'] === [])
      <p class="muted">Geen updates in deze periode.</p>
    @else
      <table class="data">
        <tr><th>Datum</th><th>Type</th><th>Onderdeel</th><th>Van</th><th>Naar</th><th></th></tr>
        @foreach ($site['updates'] as $u)
          <tr><td>{{ $date($u['date']) }}</td><td>{{ $u['kind'] }}</td><td>{{ $u['name'] }}</td><td>{{ $u['from'] }}</td><td>{{ $u['to'] }}</td><td class="{{ $u['ok'] ? 'ok' : 'bad' }}">{{ $u['ok'] ? '✓' : 'mislukt' }}</td></tr>
        @endforeach
      </table>
    @endif
  @endif

  @if ($on('pending') && $site['pending'] !== [])
    <h3>Openstaande updates</h3>
    <table class="data">
      <tr><th>Type</th><th>Onderdeel</th><th>Huidige versie</th><th>Beschikbaar</th></tr>
      @foreach ($site['pending'] as $u)
        <tr><td>{{ $u['kind'] }}</td><td>{{ $u['name'] }}</td><td>{{ $u['from'] }}</td><td>{{ $u['to'] }}</td></tr>
      @endforeach
    </table>
  @endif

  @if ($on('security'))
    @php $sec = $site['security']; @endphp
    <h3>Beveiliging</h3>
    @php
      $sentence = 'Geen mislukte inlogpogingen geregistreerd.';
      if ($sec['failed_logins'] > 0) {
          $sentence = number_format($sec['failed_logins'], 0, ',', '.') . ' mislukte inlogpogingen';
          if ($sec['channels'] !== []) {
              $sentence .= ' (' . collect($sec['channels'])->sortDesc()->map(fn ($n, $c) => ($channelLabels[$c] ?? $c) . ' ' . number_format($n, 0, ',', '.'))->implode(', ') . ')';
          }
          $sentence .= $sec['locked'] > 0 ? ', ' . $sec['locked'] . ' keer een IP-adres tijdelijk geblokkeerd.' : '.';
      }
    @endphp
    <p>{{ $sentence }}</p>
    @if ($sec['login_security'])
      <p class="small muted">
        Blokkade na mislukte pogingen: {{ !empty($sec['login_security']['lockout']) ? 'aan' : 'uit' }} ·
        XML-RPC-login: {{ ($sec['login_security']['xmlrpc'] ?? '') === 'off' ? 'uit' : 'aan' }} ·
        Gebruikersnamen afgeschermd: {{ !empty($sec['login_security']['hide_users']) ? 'ja' : 'nee' }}
      </p>
    @endif
    @if ($sec['issues'] !== [])
      <p><b>Aandachtspunten:</b></p>
      <ul>@foreach ($sec['issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>
      @if (($sec['vulnerabilities'] ?? 0) > 0)<p class="small muted">{{ \App\Ocm\Vulnerabilities::ATTRIBUTION }}</p>@endif
    @else
      <p class="ok">Geen openstaande beveiligingsproblemen gevonden.</p>
    @endif
  @endif

  @if ($on('pagespeed') && $site['pagespeed'] !== [])
    <h3>PageSpeed</h3>
    <table class="data">
      <tr><th></th><th class="num">Prestaties</th><th class="num">Toegankelijkheid</th><th class="num">Best practices</th><th class="num">SEO</th><th class="num">LCP</th></tr>
      @foreach ($site['pagespeed'] as $p)
        <tr>
          <td>{{ $p['label'] }}</td>
          <td class="num"><span class="score {{ $scoreClass($p['performance']) }}">{{ $p['performance'] ?? '–' }}</span>@if ($p['before'] !== null && $p['before'] !== $p['performance']) <span class="small muted">(was {{ $p['before'] }})</span>@endif</td>
          <td class="num"><span class="score {{ $scoreClass($p['accessibility']) }}">{{ $p['accessibility'] ?? '–' }}</span></td>
          <td class="num"><span class="score {{ $scoreClass($p['best_practices']) }}">{{ $p['best_practices'] ?? '–' }}</span></td>
          <td class="num"><span class="score {{ $scoreClass($p['seo']) }}">{{ $p['seo'] ?? '–' }}</span></td>
          <td class="num">{{ $p['lcp_ms'] !== null ? number_format($p['lcp_ms'] / 1000, 1, ',', '') . ' s' : '–' }}</td>
        </tr>
      @endforeach
    </table>
    <p class="small muted">Gemeten met Google PageSpeed Insights. 90–100 is goed, 50–89 kan beter, onder 50 is slecht.</p>
  @endif

  @if ($on('analytics') && !empty($site['analytics']) && (!empty($site['analytics']['ga4']) || !empty($site['analytics']['gsc'])))
    <h3>Bezoekers en Google</h3>
    <table class="data">
      <tr>
        @if (!empty($site['analytics']['ga4']))<th>Bezoekers</th><th>Sessies</th><th>Paginaweergaven</th>@endif
        @if (!empty($site['analytics']['gsc']))<th>Klikken in Google</th><th>Vertoningen</th><th>Gem. positie</th>@endif
      </tr>
      <tr>
        @if (!empty($site['analytics']['ga4']))
          <td>{{ number_format($site['analytics']['ga4']['users'], 0, ',', '.') }}</td><td>{{ number_format($site['analytics']['ga4']['sessions'], 0, ',', '.') }}</td><td>{{ number_format($site['analytics']['ga4']['pageviews'], 0, ',', '.') }}</td>
        @endif
        @if (!empty($site['analytics']['gsc']))
          <td>{{ number_format($site['analytics']['gsc']['clicks'], 0, ',', '.') }}</td><td>{{ number_format($site['analytics']['gsc']['impressions'], 0, ',', '.') }}</td><td>{{ number_format($site['analytics']['gsc']['position'], 1, ',', '') }}</td>
        @endif
      </tr>
    </table>
    @if (!empty($site['analytics']['ga4']['pages']))
      <table class="data">
        <tr><th>Populairste pagina's</th><th class="num">Weergaven</th></tr>
        @foreach (array_slice($site['analytics']['ga4']['pages'], 0, 5) as $p)<tr><td>{{ \Illuminate\Support\Str::limit($p['path'], 70) }}</td><td class="num">{{ number_format($p['views'], 0, ',', '.') }}</td></tr>@endforeach
      </table>
    @endif
    @if (!empty($site['analytics']['gsc']['queries']))
      <table class="data">
        <tr><th>Zoektermen in Google</th><th class="num">Klikken</th><th class="num">Positie</th></tr>
        @foreach (array_slice($site['analytics']['gsc']['queries'], 0, 5) as $q)<tr><td>{{ $q['query'] }}</td><td class="num">{{ $q['clicks'] }}</td><td class="num">{{ number_format($q['position'], 1, ',', '') }}</td></tr>@endforeach
      </table>
    @endif
    <p class="small muted">Bron: Google Analytics en Google Search Console.</p>
  @endif

  @if ($on('links'))
    <h3>Kapotte links</h3>
    @if (empty($site['broken_links']))
      <p class="ok">Geen kapotte links gevonden.</p>
    @else
      <table class="data">
        <tr><th>Link</th><th>Fout</th><th>Gevonden op</th></tr>
        @foreach ($site['broken_links'] as $l)<tr><td>{{ \Illuminate\Support\Str::limit($l['url'], 60) }}</td><td>{{ $l['error'] }}</td><td>{{ $l['page'] ? (parse_url($l['page'], PHP_URL_PATH) ?: '/') : '' }}</td></tr>@endforeach
      </table>
    @endif
  @endif

  @if ($on('environment'))
    <h3>Technische gegevens</h3>
    <table class="data">
      <tr><td>WordPress</td><td>{{ $site['wp'] ?? '–' }}</td><td>PHP</td><td>{{ $site['php'] ?? '–' }}</td></tr>
      <tr><td>Plugins</td><td>{{ $site['plugins'] }}</td><td>Thema's</td><td>{{ $site['themes'] }}</td></tr>
      <tr><td>HTTPS</td><td>{{ $site['https'] === null ? '–' : ($site['https'] ? 'ja' : 'nee') }}</td><td></td><td></td></tr>
    </table>
  @endif

  @if ($on('changes') && $site['changes'] !== [])
    <h3>Overige wijzigingen</h3>
    <table class="data">
      <tr><th>Datum</th><th>Wijziging</th><th>Onderdeel</th></tr>
      @foreach ($site['changes'] as $c)
        <tr><td>{{ $date($c['date']) }}</td><td>{{ $c['what'] }}</td><td>{{ $c['object'] }}</td></tr>
      @endforeach
    </table>
  @endif
@endforeach

@if ($d['sites'] === [])
  <p class="muted">Er zijn nog geen websites aan deze klant gekoppeld.</p>
@endif
