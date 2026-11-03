<?php

namespace App\Ocm\Monitoring;

use App\Models\Site;
use App\Models\UptimeCheck;
use App\Ocm\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Uptime, SSL-certificaat en domeinregistratie van de sites, vanuit de scheduler. Alleen publieke
 * gegevens: een GET op de homepage, de TLS-handshake en RDAP. Geen signer nodig.
 */
final class Monitor
{
    /** Pas na zoveel mislukte controles op rij geldt een site als offline (geen vals alarm bij één hapering). */
    public const FAILURES_FOR_DOWN = 2;

    /** Voor tests: vervang de TLS-controle. @var (callable(string): array{expires_at: ?int, issuer: ?string, error: ?string})|null */
    public static $sslProbe = null;

    /** @param Collection<int, Site> $sites */
    public static function checkUptime(Collection $sites): void
    {
        if ($sites->isEmpty()) {
            return;
        }
        $timeout = (int) config('ocm.monitoring.timeout_seconds', 20);
        $started = [];
        $responses = Http::pool(function (Pool $pool) use ($sites, $timeout, &$started) {
            return $sites->map(function (Site $site) use ($pool, $timeout, &$started) {
                $started[$site->id] = microtime(true);

                return $pool->as((string) $site->id)->timeout($timeout)->connectTimeout(10)
                    ->withHeaders(['User-Agent' => 'NB-OC-Monitor/1.0 (+uptime)', 'Cache-Control' => 'no-cache'])
                    ->withOptions(['allow_redirects' => ['max' => 5]])->get(rtrim($site->url, '/') . '/');
            })->all();
        });

        $now = now();
        foreach ($sites as $site) {
            $response = $responses[(string) $site->id] ?? null;
            $ms = isset($started[$site->id]) ? (int) round((microtime(true) - $started[$site->id]) * 1000) : null;
            if ($response instanceof Response) {
                $ok = $response->status() < 400;
                $status = $response->status();
                $error = $ok ? null : 'HTTP ' . $status;
                $ms = isset($response->handlerStats()['total_time']) ? (int) round($response->handlerStats()['total_time'] * 1000) : $ms;
            } else {
                $ok = false;
                $status = null;
                $error = $response instanceof ConnectionException || $response instanceof \Throwable
                    ? mb_substr(preg_replace('/\s+/', ' ', $response->getMessage()), 0, 250) : 'Geen antwoord';
            }
            UptimeCheck::query()->create(['site_id' => $site->id, 'checked_at' => $now, 'ok' => $ok, 'status' => $status, 'response_ms' => $ok ? $ms : null, 'error' => $error]);
            self::applyState($site, $ok, $ok ? $ms : null, $error);
        }
    }

    private static function applyState(Site $site, bool $ok, ?int $ms, ?string $error): void
    {
        $failures = $ok ? 0 : $site->uptime_failures + 1;
        $wasDown = $site->uptime_state === 'down';
        $state = $ok ? 'up' : ($failures >= self::FAILURES_FOR_DOWN ? 'down' : ($site->uptime_state ?? 'up'));

        if ($state === 'down' && !$wasDown) {
            // Het eerste mislukte moment telt als begin van de storing.
            $since = $site->uptime_checked_at && $failures > 1 ? $site->uptime_checked_at : now();
            $site->down_since = $since;
            Activity::log('site_down', ['error' => $error], $site, null);
        } elseif ($state === 'up' && $wasDown) {
            Activity::log('site_up', ['down_minutes' => $site->down_since ? (int) $site->down_since->diffInMinutes(now()) : null], $site, null);
            $site->down_since = null;
        }
        $site->forceFill(['uptime_state' => $state, 'uptime_failures' => min($failures, 65000), 'uptime_checked_at' => now(), 'response_ms' => $ms ?? $site->response_ms])->save();
    }

    public static function checkSsl(Site $site): void
    {
        $host = parse_url($site->url, PHP_URL_HOST);
        $port = parse_url($site->url, PHP_URL_PORT) ?: 443;
        if (parse_url($site->url, PHP_URL_SCHEME) !== 'https' || !is_string($host)) {
            $site->forceFill(['ssl_expires_at' => null, 'ssl_issuer' => null, 'ssl_error' => 'Site gebruikt geen https', 'ssl_checked_at' => now()])->save();
            return;
        }
        $result = self::$sslProbe ? (self::$sslProbe)($host) : self::probeTls($host, (int) $port);
        $site->forceFill([
            'ssl_expires_at' => $result['expires_at'] ? CarbonImmutable::createFromTimestamp($result['expires_at']) : null,
            'ssl_issuer' => $result['issuer'] ? mb_substr($result['issuer'], 0, 190) : null,
            'ssl_error' => $result['error'] ? mb_substr($result['error'], 0, 255) : null,
            'ssl_checked_at' => now(),
        ])->save();
    }

    /** @return array{expires_at: ?int, issuer: ?string, error: ?string} */
    private static function probeTls(string $host, int $port): array
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host,
        ]]);
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if ($client === false) {
            // Ongeldig certificaat? Dan toch de vervaldatum ophalen, zonder verificatie, voor de melding.
            $insecure = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
            $client = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr2, 15, STREAM_CLIENT_CONNECT, $insecure);
            $error = 'Certificaat niet geldig of niet bereikbaar' . ($errstr !== '' ? ': ' . $errstr : '');
            if ($client === false) {
                return ['expires_at' => null, 'issuer' => null, 'error' => $error];
            }
        }
        $cert = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);
        $info = $cert ? openssl_x509_parse($cert) : false;
        if (!is_array($info)) {
            return ['expires_at' => null, 'issuer' => null, 'error' => $error ?? 'Certificaat niet leesbaar'];
        }

        return ['expires_at' => (int) $info['validTo_time_t'], 'issuer' => (string) ($info['issuer']['O'] ?? $info['issuer']['CN'] ?? ''), 'error' => $error ?? null];
    }

    /** Domeinregistratie via RDAP. Niet elk register publiceert een vervaldatum (SIDN voor .nl niet). */
    public static function checkDomain(Site $site): void
    {
        $domain = self::registrableDomain((string) parse_url($site->url, PHP_URL_HOST));
        $expires = null;
        $error = null;
        try {
            $response = Http::timeout(20)->acceptJson()->withOptions(['allow_redirects' => ['max' => 3]])
                ->get('https://rdap.org/domain/' . rawurlencode($domain));
            if ($response->status() === 404) {
                $error = 'Geen RDAP-gegevens voor ' . $domain;
            } elseif (!$response->successful()) {
                $error = 'RDAP gaf HTTP ' . $response->status();
            } else {
                foreach ((array) $response->json('events', []) as $event) {
                    if (is_array($event) && ($event['eventAction'] ?? '') === 'expiration' && is_string($event['eventDate'] ?? null)) {
                        $expires = CarbonImmutable::parse($event['eventDate']);
                    }
                }
                $error = $expires ? null : 'Het register van ' . $domain . ' publiceert geen vervaldatum';
            }
        } catch (\Throwable $e) {
            $error = 'RDAP niet bereikbaar';
        }
        $site->forceFill(['domain_expires_at' => $expires, 'domain_error' => $error, 'domain_checked_at' => now()])->save();
    }

    /** Eenvoudig: de laatste twee labels, of drie bij bekende tweedelige extensies (co.uk, com.au, ...). */
    public static function registrableDomain(string $host): string
    {
        $labels = explode('.', strtolower(trim($host, '.')));
        $two = implode('.', array_slice($labels, -2));
        $secondLevel = ['co.uk', 'org.uk', 'ac.uk', 'com.au', 'net.au', 'org.au', 'co.nz', 'co.za', 'com.br', 'co.jp', 'com.tr', 'com.mx', 'co.in'];

        return count($labels) > 2 && in_array($two, $secondLevel, true) ? implode('.', array_slice($labels, -3)) : $two;
    }

    /**
     * Uptime-percentage en storingen in een periode, uit de losse controles.
     *
     * @return array{percent: ?float, checks: int, incidents: int, down_minutes: int, avg_ms: ?int}
     */
    public static function stats(Site $site, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $checks = UptimeCheck::query()->where('site_id', $site->id)->whereBetween('checked_at', [$from, $to])->orderBy('checked_at')->get(['checked_at', 'ok', 'response_ms']);
        if ($checks->isEmpty()) {
            return ['percent' => null, 'checks' => 0, 'incidents' => 0, 'down_minutes' => 0, 'avg_ms' => null];
        }
        $incidents = 0;
        $downMinutes = 0;
        $run = 0;
        $interval = max(1, (int) config('ocm.monitoring.interval_minutes', 5));
        foreach ($checks as $check) {
            if ($check->ok) {
                if ($run >= self::FAILURES_FOR_DOWN) {
                    $incidents++;
                    $downMinutes += $run * $interval;
                }
                $run = 0;
            } else {
                $run++;
            }
        }
        if ($run >= self::FAILURES_FOR_DOWN) {
            $incidents++;
            $downMinutes += $run * $interval;
        }
        $ok = $checks->where('ok', true);

        return [
            'percent' => round($ok->count() / $checks->count() * 100, 2),
            'checks' => $checks->count(),
            'incidents' => $incidents,
            'down_minutes' => $downMinutes,
            'avg_ms' => $ok->isNotEmpty() ? (int) round($ok->avg('response_ms')) : null,
        ];
    }
}
