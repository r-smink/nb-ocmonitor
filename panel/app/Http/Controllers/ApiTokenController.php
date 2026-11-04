<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Ocm\Activity;
use App\Ocm\ApiAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Instellingen → API: tokens voor de alleen-lezen API (alleen admins). */
class ApiTokenController extends Controller
{
    public function index(Request $request): View
    {
        return view('settings.api', [
            'tokens' => ApiToken::query()->with('creator')->orderByDesc('id')->get(),
            'plain' => $request->session()->get('api_token_plain'),
            'enabled' => ApiAccess::enabled(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'expires' => ['nullable', 'in:30,90,365'],
        ]);
        $expires = isset($data['expires']) ? CarbonImmutable::now()->addDays((int) $data['expires']) : null;
        [$token, $plain] = ApiToken::issue(trim($data['name']), $request->user(), $expires);
        Activity::log('api_token_created', ['token_id' => $token->id, 'name' => $token->name, 'expires_at' => $expires?->toDateString()]);

        // Eenmalig tonen: alleen in de volgende request, daarna is alleen de hash er nog.
        return redirect()->route('settings.api')->with('api_token_plain', $plain)->with('status', "Token \"{$token->name}\" aangemaakt. Kopieer het nu; je ziet het niet opnieuw.");
    }

    public function toggle(Request $request): RedirectResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        ApiAccess::set($enabled);
        Activity::log('api_toggled', ['enabled' => $enabled]);

        return back()->with('status', $enabled ? 'De API staat aan. Verzoeken met een geldig token werken weer.' : 'De API staat uit. Alle /api/v1-verzoeken krijgen nu een 404; de tokens blijven bewaard.');
    }

    public function destroy(ApiToken $token): RedirectResponse
    {
        $token->forceFill(['revoked_at' => now()])->save();
        Activity::log('api_token_revoked', ['token_id' => $token->id, 'name' => $token->name]);

        return back()->with('status', "Token \"{$token->name}\" ingetrokken.");
    }
}
