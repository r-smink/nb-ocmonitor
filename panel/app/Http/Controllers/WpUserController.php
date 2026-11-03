<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Ocm\Activity;
use App\Ocm\JobQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Beheerders en redacteuren van alle sites (uit users.list). Acties gaan als ondertekende
 * opdracht naar de site; het panel kan geen gebruikers aanmaken of wachtwoorden kiezen.
 */
class WpUserController extends Controller
{
    public const ACTIONS = [
        'lock' => ['users.lock', 'Blokkeren'],
        'unlock' => ['users.unlock', 'Deblokkeren'],
        'logout' => ['users.logout', 'Overal uitloggen'],
        'reset_password' => ['users.reset_password', 'Wachtwoordreset afdwingen'],
        'demote' => ['users.demote', 'Rol verlagen'],
    ];

    public const ROLES = ['administrator' => 'Beheerder', 'editor' => 'Redacteur', 'shop_manager' => 'Winkelmanager'];

    public const TWO_FACTOR_MODES = ['off' => 'Uit', 'admins' => 'Beheerders', 'editors' => 'Beheerders en redacteuren'];

    public function index(Request $request): View
    {
        $sites = Site::query()->whereNotNull('public_key')->orderBy('name')->get();
        $filters = [
            'site' => (string) $request->query('site', ''),
            'role' => array_key_exists((string) $request->query('role'), self::ROLES) ? (string) $request->query('role') : '',
            'twofa' => $request->query('twofa') === 'zonder' ? 'zonder' : '',
            'q' => trim((string) $request->query('q', '')),
        ];

        $rows = collect();
        foreach ($sites as $site) {
            foreach ((array) (($site->wp_users ?? [])['users'] ?? []) as $user) {
                $rows->push($user + ['site' => $site]);
            }
        }
        $filtered = $rows->filter(fn ($u) => ($filters['site'] === '' || $u['site']->site_id === $filters['site'])
            && ($filters['role'] === '' || in_array($filters['role'], $u['roles'], true))
            && ($filters['twofa'] === '' || empty($u['two_factor']))
            && ($filters['q'] === '' || Str::contains(Str::lower($u['login'] . ' ' . $u['email'] . ' ' . $u['name'] . ' ' . $u['site']->name), Str::lower($filters['q']))))
            ->sortBy(fn ($u) => (in_array('administrator', $u['roles'], true) ? '0' : '1') . Str::lower($u['site']->name . ' ' . $u['login']))->values();

        return view('wp-users.index', [
            'rows' => $filtered,
            'sites' => $sites,
            'filters' => $filters,
            'totals' => [
                'admins' => $rows->filter(fn ($u) => in_array('administrator', $u['roles'], true))->count(),
                'without_2fa' => $rows->filter(fn ($u) => in_array('administrator', $u['roles'], true) && empty($u['two_factor']) && empty($u['locked']))->count(),
                'locked' => $rows->where('locked', true)->count(),
                'unknown' => $sites->filter(fn (Site $s) => $s->wp_users === null)->count(),
            ],
        ]);
    }

    public function refresh(Request $request): RedirectResponse
    {
        $queued = 0;
        foreach (Site::query()->whereNotNull('public_key')->get() as $site) {
            if ($site->usersBlockedReason() === null) {
                JobQueue::enqueue($site, 'users.list', [], $request->user());
                $queued++;
            }
        }

        return back()->with('status', $queued > 0 ? "Gebruikers worden opgehaald bij {$queued} site(s)." : 'Geen sites met client-plugin 0.4.0 of nieuwer.');
    }

    public function action(Request $request, Site $site, int $userId): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::ACTIONS))],
            'role' => ['required_if:action,demote', 'nullable', Rule::in(['editor', 'author', 'contributor', 'subscriber'])],
        ]);
        if ($reason = $site->usersBlockedReason()) {
            return back()->withErrors(['users' => $reason]);
        }
        $user = collect((array) (($site->wp_users ?? [])['users'] ?? []))->firstWhere('id', $userId);
        abort_if($user === null, 404);

        $args = ['user_id' => $userId] + ($data['action'] === 'demote' ? ['role' => $data['role']] : []);
        $job = JobQueue::enqueue($site, self::ACTIONS[$data['action']][0], $args, $request->user(), false);
        Activity::log('wp_user_action_requested', ['job_id' => $job->job_id, 'action' => $data['action'], 'login' => $user['login']] + $args, $site);

        return back()->with('status', self::ACTIONS[$data['action']][1] . ': ' . $user['login'] . ' op ' . $site->name . ' staat in de wachtrij.');
    }

    /** 2FA-beleid voor één site, of (zonder site) voor alle sites met plugin 0.4.0+. */
    public function twoFactor(Request $request, ?Site $site = null): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(array_keys(self::TWO_FACTOR_MODES))]]);
        $targets = $site ? collect([$site]) : Site::query()->whereNotNull('public_key')->get();
        $queued = 0;
        foreach ($targets as $target) {
            if ($target->usersBlockedReason() === null) {
                JobQueue::enqueue($target, 'security.two_factor', ['mode' => $data['mode']], $request->user(), false);
                $queued++;
            }
        }
        Activity::log('two_factor_policy_requested', ['mode' => $data['mode'], 'sites' => $queued], $site);

        return back()->with('status', "Tweestapsverificatie '" . self::TWO_FACTOR_MODES[$data['mode']] . "' wordt ingesteld op {$queued} site(s).");
    }
}
