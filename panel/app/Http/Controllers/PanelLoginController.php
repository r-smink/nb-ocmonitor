<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\Activity;
use App\Ocm\JobQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inloggen in WP-admin vanuit het panel (alleen portalgebruikers met de rol admin). De site maakt
 * een eenmalige link van 60 seconden; die wordt na gebruik uit het jobarchief gehaald.
 */
class PanelLoginController extends Controller
{
    public const MIN_VERSION = '0.11.0';

    public static function blockedReason(Site $site): ?string
    {
        if (!$site->isPaired()) {
            return 'Site is niet gekoppeld';
        }
        if ($site->plugin_version === null || version_compare($site->plugin_version, self::MIN_VERSION, '<')) {
            return 'Vanaf client-plugin ' . self::MIN_VERSION;
        }
        if (!$site->hasScope('users')) {
            return 'Gebruikersbeheer staat niet aan op de site';
        }
        if ((($site->inventory ?? [])['environment']['panel_login'] ?? true) === false) {
            return 'Inloggen vanuit het panel staat uit op de site';
        }

        return null;
    }

    public function start(Request $request, Site $site): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'min:1']]);
        if ($reason = self::blockedReason($site)) {
            return back()->withErrors(['login' => $reason]);
        }
        $user = collect((array) (($site->wp_users ?? [])['users'] ?? []))->firstWhere('id', (int) $data['user_id']);
        abort_if($user === null, 404);

        $job = JobQueue::enqueue($site, 'auth.login_link', ['user_id' => (int) $data['user_id'], 'panel_user' => (string) $request->user()->name], $request->user(), false);
        Activity::log('panel_login_requested', ['job_id' => $job->job_id, 'login' => $user['login']], $site);

        return redirect()->route('sites.login.wait', [$site, $job]);
    }

    public function wait(Request $request, Site $site, SiteJob $job): View|Response|RedirectResponse
    {
        abort_unless($job->site_id === $site->id && $job->command === 'auth.login_link' && $job->requested_by === $request->user()->id, 404);
        if ($job->created_at->lt(now()->subMinutes(3))) {
            return response()->view('sites.login-wait', ['site' => $site, 'error' => 'Deze aanvraag is verlopen. Vraag een nieuwe inloglink aan.'], 410);
        }
        if (in_array($job->status, ['failed', 'rejected'], true)) {
            return view('sites.login-wait', ['site' => $site, 'error' => $job->error ?: 'Inloggen is niet gelukt.']);
        }
        $url = ($job->result ?? [])['url'] ?? null;
        // Alleen doorsturen naar de eigen host van de site (geen open redirect).
        $sameHost = is_string($url) && str_starts_with($url, 'https://') || is_string($url) && config('ocm.allow_http_sites');
        $sameHost = $sameHost && strtolower((string) parse_url($url, PHP_URL_HOST)) === strtolower((string) parse_url($site->url, PHP_URL_HOST));
        if ($job->status === 'done' && $sameHost) {
            // Eenmalig: de link gaat uit het archief zodra hij is gebruikt.
            $job->update(['result' => ['user' => ($job->result ?? [])['user'] ?? null, 'used_at' => now()->toIso8601String()]]);
            Activity::log('panel_login_used', ['job_id' => $job->job_id, 'login' => ($job->result ?? [])['user'] ?? null], $site);

            return redirect()->away($url);
        }
        if ($job->status === 'done') {
            return view('sites.login-wait', ['site' => $site, 'error' => 'De link is al gebruikt. Vraag een nieuwe aan.']);
        }

        return view('sites.login-wait', ['site' => $site, 'error' => null]);
    }
}
