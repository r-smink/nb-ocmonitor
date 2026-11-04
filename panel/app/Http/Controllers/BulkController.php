<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\Activity;
use App\Ocm\JobQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Bulk-acties over meerdere sites: plugins installeren (alleen wordpress.org), activeren,
 * deactiveren en verwijderen, de onderhoudsmodus, en een WP-gebruiker overal blokkeren of
 * uitloggen. Per site wordt een gewone opdracht klaargezet; de signer ondertekent ze.
 */
class BulkController extends Controller
{
    public const MIN_VERSION = '0.9.0';

    public const ACTIONS = [
        'install' => 'Plugin installeren (wordpress.org)',
        'activate' => 'Plugin activeren',
        'deactivate' => 'Plugin deactiveren',
        'delete' => 'Plugin verwijderen',
        'maintenance_on' => 'Onderhoudsmodus aan',
        'maintenance_off' => 'Onderhoudsmodus uit',
        'user_lock' => 'WP-gebruiker blokkeren',
        'user_logout' => 'WP-gebruiker overal uitloggen',
    ];

    public const COMMANDS = ['plugin.install', 'plugin.activate', 'plugin.deactivate', 'plugin.delete', 'site.maintenance', 'users.lock', 'users.logout'];

    public function index(): View
    {
        $sites = Site::query()->with(['tags', 'customer.tags'])->whereNotNull('public_key')->orderBy('name')->get();

        return view('bulk.index', [
            'sites' => $sites,
            'plugins' => self::knownPlugins($sites),
            'jobs' => SiteJob::query()->with(['site', 'requester'])->whereIn('command', self::COMMANDS)->orderByDesc('id')->limit(30)->get(),
            'actions' => self::ACTIONS,
            'minVersion' => self::MIN_VERSION,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::ACTIONS))],
            'sites' => ['nullable', 'array', 'max:1000'], 'sites.*' => ['integer'],
            'slug' => ['required_if:action,install', 'nullable', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,99}$/'],
            'activate' => ['nullable', 'boolean'],
            'plugin' => ['required_if:action,activate,deactivate,delete', 'nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)?\.php$/'],
            'message' => ['nullable', 'string', 'max:500'],
            'hours' => ['nullable', 'integer', 'between:1,168'],
            'login' => ['required_if:action,user_lock,user_logout', 'nullable', 'string', 'max:191'],
        ], ['slug.regex' => 'Gebruik de slug van wordpress.org, bijv. "wordfence".', 'plugin.regex' => 'Kies een plugin uit de lijst.']);
        $action = $data['action'];
        $user = in_array($action, ['user_lock', 'user_logout'], true);
        if (!$user && empty($data['sites'])) {
            return back()->withInput()->withErrors(['sites' => 'Kies minstens één site.']);
        }
        if (in_array($data['plugin'] ?? '', ['nb-ocmonitor-client/nb-ocmonitor-client.php'], true) && in_array($action, ['deactivate', 'delete'], true)) {
            return back()->withInput()->withErrors(['plugin' => 'NB OC Monitor zelf kan niet uitgezet of verwijderd worden.']);
        }

        $sites = Site::query()->whereNotNull('public_key')->when(!empty($data['sites']), fn ($q) => $q->whereIn('id', $data['sites']))->orderBy('name')->get();
        $queued = 0;
        $skipped = [];
        foreach ($sites as $site) {
            [$command, $args, $reason] = $this->planFor($site, $action, $data);
            if ($reason !== null) {
                if (!$user || $reason !== 'geen gebruiker') {
                    $skipped[] = $site->name . ': ' . $reason;
                }
                continue;
            }
            foreach ($args as $a) {
                JobQueue::enqueue($site, $command, $a, $request->user(), false);
                $queued++;
            }
        }
        Activity::log('bulk_action', ['action' => $action, 'slug' => $data['slug'] ?? null, 'plugin' => $data['plugin'] ?? null, 'login' => $data['login'] ?? null,
            'sites' => $sites->count(), 'queued' => $queued, 'skipped' => count($skipped)]);

        $message = self::ACTIONS[$action] . ": {$queued} opdracht(en) klaargezet" . ($skipped ? ', ' . count($skipped) . ' site(s) overgeslagen' : '') . '.';
        $redirect = redirect()->route('bulk.index')->with('status', $message);

        return $skipped !== [] ? $redirect->withErrors(['bulk' => array_slice($skipped, 0, 20)]) : $redirect;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: ?string, 1: list<array<string, mixed>>, 2: ?string} command, argumentenlijst, reden om over te slaan
     */
    private function planFor(Site $site, string $action, array $data): array
    {
        if (in_array($action, ['user_lock', 'user_logout'], true)) {
            if ($reason = $site->usersBlockedReason()) {
                return [null, [], $reason];
            }
            $login = mb_strtolower(trim((string) $data['login']));
            $matches = collect((array) (($site->wp_users ?? [])['users'] ?? []))
                ->filter(fn ($u) => mb_strtolower((string) ($u['login'] ?? '')) === $login || mb_strtolower((string) ($u['email'] ?? '')) === $login);
            if ($matches->isEmpty()) {
                return [null, [], 'geen gebruiker'];
            }

            return [$action === 'user_lock' ? 'users.lock' : 'users.logout', $matches->map(fn ($u) => ['user_id' => (int) $u['id']])->values()->all(), null];
        }
        if (!$site->supports(self::MIN_VERSION)) {
            return [null, [], 'client-plugin ' . ($site->plugin_version ?? '?') . ' (nodig: ' . self::MIN_VERSION . ')'];
        }
        if (str_starts_with($action, 'maintenance')) {
            if (!$site->hasScope('maintenance')) {
                return [null, [], 'onderhoud staat niet aan op de site'];
            }
            $on = $action === 'maintenance_on';
            $args = ['enabled' => $on];
            if ($on) {
                $args += ['message' => trim((string) ($data['message'] ?? '')), 'until' => now()->addHours((int) ($data['hours'] ?? 4))->getTimestamp()];
            }

            return ['site.maintenance', [$args], null];
        }
        if ($reason = $site->updatesBlockedReason()) {
            return [null, [], $reason];
        }
        $plugins = collect((array) (($site->inventory ?? [])['plugins'] ?? []))->keyBy('file');
        if ($action === 'install') {
            if ($plugins->contains(fn ($p) => ($p['slug'] ?? null) === $data['slug']) && empty($data['activate'])) {
                return [null, [], 'al geïnstalleerd'];
            }

            return ['plugin.install', [['slug' => $data['slug'], 'activate' => !empty($data['activate'])]], null];
        }
        $plugin = $plugins->get($data['plugin']);
        if ($plugin === null) {
            return [null, [], 'plugin niet geïnstalleerd'];
        }
        if ($action === 'activate' && !empty($plugin['active'])) {
            return [null, [], 'al actief'];
        }
        if ($action === 'deactivate' && empty($plugin['active'])) {
            return [null, [], 'al inactief'];
        }
        if ($action === 'delete' && !empty($plugin['active'])) {
            return [null, [], 'nog actief: deactiveer eerst'];
        }

        return ['plugin.' . $action, [['plugins' => [$data['plugin']]]], null];
    }

    /**
     * @param Collection<int, Site> $sites
     * @return Collection<string, array{file: string, name: string, sites: int, active: int}>
     */
    public static function knownPlugins(Collection $sites): Collection
    {
        $out = [];
        foreach ($sites as $site) {
            foreach ((array) (($site->inventory ?? [])['plugins'] ?? []) as $p) {
                if (!is_array($p) || empty($p['file'])) {
                    continue;
                }
                $out[$p['file']] ??= ['file' => $p['file'], 'name' => (string) ($p['name'] ?? $p['file']), 'sites' => 0, 'active' => 0];
                $out[$p['file']]['sites']++;
                $out[$p['file']]['active'] += empty($p['active']) ? 0 : 1;
            }
        }

        return collect($out)->sortBy(fn ($p) => mb_strtolower($p['name']));
    }
}
