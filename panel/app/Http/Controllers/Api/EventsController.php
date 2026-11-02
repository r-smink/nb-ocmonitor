<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Ocm\DbNonceStore;
use App\Ocm\EventIngestor;
use App\Ocm\JobQueue;
use App\Ocm\PanelKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NextBuzz\OcMonitor\Protocol\Canonical;
use NextBuzz\OcMonitor\Protocol\StaticKeyResolver;
use NextBuzz\OcMonitor\Protocol\VerificationFailed;
use NextBuzz\OcMonitor\Protocol\Verifier;

/**
 * POST /api/sites/{site_id}/events: door de site gepushte events, ondertekend met de site-sleutel.
 * Host en pad komen uit APP_URL, zoals de site ze ondertekent; nooit uit de Host-header.
 */
class EventsController extends Controller
{
    private const MAX_BODY_BYTES = 1048576;

    public function __invoke(Request $request, string $siteId, EventIngestor $ingestor): JsonResponse
    {
        $body = (string) $request->getContent();
        $site = Site::query()->where('site_id', $siteId)->first();
        if ($site === null || !$site->isPaired() || strlen($body) > self::MAX_BODY_BYTES) {
            return self::notFound();
        }

        try {
            (new Verifier(new StaticKeyResolver([$site->publicKeyRaw()]), new DbNonceStore()))->verifyRequest(
                Canonical::CTX_SITE,
                'POST',
                PanelKeys::panelHost(),
                PanelKeys::panelBasePath() . '/api/sites/' . $site->site_id . '/events',
                $site->site_id,
                $request->headers->all(),
                $body,
                time()
            );
        } catch (VerificationFailed $e) {
            return self::notFound();
        }

        $payload = json_decode($body, true, 16);
        if (!is_array($payload) || !is_array($payload['events'] ?? null)) {
            return response()->json(['ok' => false, 'error' => 'bad_request'], 400);
        }

        $outcome = $ingestor->ingest($site, $payload['events']);
        $site->forceFill(['last_seen_at' => now()])->save();
        if ($outcome['needs_pull']) {
            JobQueue::enqueue($site->fresh(), 'events.pull', ['after_seq' => (int) $site->fresh()->last_event_seq]);
        }

        return response()->json(['ok' => true, 'received' => $outcome['inserted']]);
    }

    private static function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not Found'], 404);
    }
}
