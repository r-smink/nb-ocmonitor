<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Ocm\ApiAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Alleen-lezen API: Authorization: Bearer ocm_… (Instellingen → API). */
class ApiTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Uitgeschakeld (Instellingen → API): doen alsof de API niet bestaat.
        if (!ApiAccess::enabled()) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $token = ApiToken::findActive((string) $request->bearerToken());
        if ($token === null) {
            return response()->json(['error' => 'unauthorized', 'message' => 'Geldig API-token vereist (Authorization: Bearer …).'], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
