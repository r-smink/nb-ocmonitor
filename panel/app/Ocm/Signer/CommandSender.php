<?php

namespace App\Ocm\Signer;

use App\Models\Site;
use App\Models\SiteJob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use NextBuzz\OcMonitor\Protocol\Canonical;
use NextBuzz\OcMonitor\Protocol\Headers;
use NextBuzz\OcMonitor\Protocol\InMemoryNonceStore;
use NextBuzz\OcMonitor\Protocol\KeyPair;
use NextBuzz\OcMonitor\Protocol\Signer;
use NextBuzz\OcMonitor\Protocol\StaticKeyResolver;
use NextBuzz\OcMonitor\Protocol\VerificationFailed;
use NextBuzz\OcMonitor\Protocol\Verifier;

/**
 * Ondertekent een job, stuurt hem naar de site en verifieert het ondertekende antwoord.
 * Draait alleen in de signer-container (heeft de private key).
 */
final class CommandSender
{
    public const ROUTE = '/nb-ocm/v1/cmd';

    private KeyPair $keys;

    public function __construct(KeyPair $keys)
    {
        $this->keys = $keys;
    }

    public function send(SiteJob $job, Site $site): SendResult
    {
        $sitePublicKey = $site->publicKeyRaw();
        if ($sitePublicKey === null) {
            return SendResult::failed('Site is niet gekoppeld');
        }

        $now = time();
        $body = (string) json_encode([
            'command' => $job->command,
            'args' => (object) ($job->args ?? []),
            'job_id' => $job->job_id,
            'expires_at' => $now + (int) config('ocm.signer.job_lifetime_seconds'),
        ], JSON_UNESCAPED_SLASHES);
        $headers = (new Signer($this->keys))->signRequest(Canonical::CTX_COMMAND, 'POST', $site->host, self::ROUTE, $site->site_id, $body, $now);

        // rest_route werkt ongeacht de permalink-instellingen van de site.
        $url = rtrim($site->url, '/') . '/?rest_route=' . self::ROUTE;

        try {
            $response = Http::withHeaders($headers + ['Content-Type' => 'application/json', 'User-Agent' => 'NB-OC-Monitor/1.0'])
                ->withBody($body, 'application/json')
                ->timeout((int) config('ocm.signer.http_timeout_seconds'))
                ->connectTimeout(10)
                ->withoutRedirecting()
                ->post($url);
        } catch (ConnectionException $e) {
            return SendResult::unreachable('Verbinding mislukt: ' . mb_substr($e->getMessage(), 0, 300));
        }

        $status = $response->status();
        $responseBody = $response->body();
        if (strlen($responseBody) > (int) config('ocm.signer.max_response_bytes')) {
            return SendResult::failed('Antwoord te groot', $status);
        }

        $responseHeaders = [];
        foreach ([Headers::KEY, Headers::TS, Headers::SIG] as $name) {
            $responseHeaders[$name] = $response->header($name);
        }

        try {
            (new Verifier(new StaticKeyResolver([$sitePublicKey]), new InMemoryNonceStore()))
                ->verifyResponse($site->site_id, $headers[Headers::NONCE], $status, $responseHeaders, $responseBody, time());
        } catch (VerificationFailed $e) {
            $hint = $status === 404 ? 'plugin niet actief, ontkoppeld of verzoek geweigerd' : 'antwoord niet ondertekend door de site';
            return SendResult::failed(sprintf('HTTP %d: %s (%s)', $status, $hint, $e->reason()), $status);
        }

        $payload = json_decode($responseBody, true, 64);
        if (!is_array($payload)) {
            return SendResult::failed('Ongeldig JSON-antwoord', $status);
        }
        if (($payload['ok'] ?? false) !== true) {
            $error = (array) ($payload['error'] ?? []);
            return SendResult::failed(sprintf('%s: %s', $error['code'] ?? 'error', $error['message'] ?? 'onbekende fout'), $status);
        }

        return SendResult::ok(is_array($payload['result'] ?? null) ? $payload['result'] : [], $status);
    }
}
