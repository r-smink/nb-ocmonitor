<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Ocm\Activity;
use App\Ocm\JobQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use NextBuzz\OcMonitor\Protocol\Base64Url;
use NextBuzz\OcMonitor\Protocol\Canonical;
use NextBuzz\OcMonitor\Protocol\KeyPair;
use NextBuzz\OcMonitor\Protocol\Pairing;
use NextBuzz\OcMonitor\Protocol\VerificationFailed;

/**
 * POST /api/pair: de plugin stuurt haar koppelbewijs (docs/protocol.md §2).
 * Elke fout geeft dezelfde generieke 404; de reden komt alleen in het activity log.
 */
class PairController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $raw = (string) $request->getContent();
        $proof = strlen($raw) <= 8192 ? json_decode($raw, true, 4) : null;
        if (!is_array($proof) || !is_string($proof['site_id'] ?? null)) {
            return self::notFound();
        }

        return DB::transaction(function () use ($proof, $request): JsonResponse {
            $site = Site::query()->where('site_id', $proof['site_id'])->lockForUpdate()->first();
            if ($site === null || !$site->pairingIsOpen()) {
                return self::notFound();
            }

            try {
                $sitePublicKey = Pairing::verifyProof($proof, $site->site_id, Base64Url::decode((string) $site->pairing_secret), time());
            } catch (VerificationFailed $e) {
                // Geldige HMAC maar ongeldig bezitsbewijs: de code is bekend bij iemand, dus direct intrekken.
                if (str_contains($e->getMessage(), 'proof of possession')) {
                    $site->pairing_secret = null;
                    $site->save();
                }
                Activity::log('pairing_rejected', ['reason' => $e->reason()], $site, null, $request->ip());
                return self::notFound();
            }

            // De code mag alleen gebruikt worden door de site waarvoor hij is aangemaakt. Met of zonder
            // www telt als dezelfde site; dan neemt het panel het adres over dat de site zelf gebruikt.
            $proofUrl = parse_url((string) $proof['site_url']);
            $host = ($proofUrl['host'] ?? '') . (isset($proofUrl['port']) ? ':' . $proofUrl['port'] : '');
            try {
                $host = Canonical::host($host);
                $hostMatches = Site::bareHost($host) === Site::bareHost($site->host);
            } catch (VerificationFailed $e) {
                $hostMatches = false;
            }
            $scheme = $proofUrl['scheme'] ?? '';
            $schemeOk = $scheme === 'https' || ($scheme === 'http' && config('ocm.allow_http_sites'));
            $reason = !$hostMatches || !$schemeOk ? 'host_mismatch'
                : ($host !== $site->host && Site::findByBareHost($host, $site->id) !== null ? 'host_in_use' : null);
            if ($reason !== null) {
                $site->pairing_secret = null;
                $site->save();
                Activity::log('pairing_rejected', ['reason' => $reason, 'site_url' => mb_substr((string) $proof['site_url'], 0, 255)], $site, null, $request->ip());
                return self::notFound();
            }
            if ($host !== $site->host) {
                $url = $scheme . '://' . $host . rtrim((string) ($proofUrl['path'] ?? ''), '/');
                Activity::log('site_host_adopted', ['from' => $site->url, 'to' => $url], $site, null, $request->ip());
                $site->host = $host;
                $site->url = $url;
            }

            $site->public_key = Base64Url::encode($sitePublicKey);
            $site->key_id = KeyPair::keyIdFor($sitePublicKey);
            $site->pairing_secret = null;
            $site->pairing_expires_at = null;
            $site->paired_at = now();
            $site->status = 'online';
            $site->last_seen_at = now();
            $site->save();

            Activity::log('site_paired', ['site_key_id' => $site->key_id, 'site_url' => $proof['site_url']], $site, null, $request->ip());
            JobQueue::enqueue($site, 'site.info');
            JobQueue::enqueue($site, 'site.inventory', ['refresh' => true]);

            return response()->json(['ok' => true, 'site_id' => $site->site_id]);
        });
    }

    private static function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not Found'], 404);
    }
}
