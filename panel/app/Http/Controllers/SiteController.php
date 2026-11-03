<?php

namespace App\Http\Controllers;

use App\Models\PagespeedResult;
use App\Models\Site;
use App\Ocm\Activity;
use App\Ocm\JobQueue;
use App\Ocm\PanelKeys;
use App\Ocm\PluginPackage;
use App\Ocm\SiteIssues;
use App\Ocm\Updates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use NextBuzz\OcMonitor\Protocol\Canonical;
use NextBuzz\OcMonitor\Protocol\Base64Url;
use NextBuzz\OcMonitor\Protocol\Pairing;

class SiteController extends Controller
{
    /** Opdrachten die een medewerker handmatig mag starten (signer-allowlist is leidend). */
    private const MANUAL_COMMANDS = [
        'site.info' => 'Heartbeat',
        'site.inventory' => 'Inventaris',
    ];

    public function index(Request $request): View
    {
        $all = Site::query()->orderBy('name')->get();
        SiteIssues::load($all);
        $sites = $all;
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $sites = $sites->filter(fn (Site $s) => Str::contains(Str::lower($s->name . ' ' . $s->url . ' ' . $s->client_name), Str::lower($q)));
        }
        $order = ['crit' => 0, 'warn' => 1, 'pending' => 2, 'ok' => 3];
        $sites = $sites->sortBy(fn (Site $s) => $order[$s->level()] . Str::lower($s->name))->values();

        $counts = ['crit' => 0, 'warn' => 0, 'ok' => 0, 'pending' => 0];
        foreach ($all as $site) {
            $counts[$site->level()]++;
        }

        return view('sites.index', ['sites' => $sites, 'counts' => $counts, 'q' => $q]);
    }

    public function create(): View
    {
        return view('sites.create', ['pluginVersion' => PluginPackage::version(), 'customers' => \App\Models\Customer::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'url' => ['required', 'string', 'max:255', 'url:' . (config('ocm.allow_http_sites') ? 'http,https' : 'https')],
        ], [
            'url.url' => 'Vul een volledige URL in die met https:// begint.',
        ]);

        $url = rtrim($data['url'], '/');
        $parts = parse_url($url);
        try {
            $host = Canonical::host(($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['url' => 'Deze URL bevat geen geldige hostnaam.']);
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['url' => 'Gebruik de basis-URL van de site, zonder ? of #.']);
        }

        $site = new Site([
            'site_id' => 'site_' . Str::lower(Str::random(20)),
            'name' => $data['name'],
            'customer_id' => $data['customer_id'] ?? null,
            'client_name' => isset($data['customer_id']) ? \App\Models\Customer::query()->whereKey($data['customer_id'])->value('name') : null,
            'url' => $url,
            'host' => $host,
        ]);
        $site->scopes = Site::DEFAULT_SCOPES;
        $this->openPairing($site);
        $site->save();

        Activity::log('site_created', ['name' => $site->name, 'url' => $site->url], $site);

        return redirect()->route('sites.show', $site)->with('status', 'Site aangemaakt. Installeer de plugin en plak de koppelcode.');
    }

    public function show(Site $site): View
    {
        $pairingCode = null;
        if ($site->pairingIsOpen()) {
            $pairingCode = Pairing::createCode(
                (string) config('app.url'),
                $site->site_id,
                PanelKeys::publicKey(),
                Base64Url::decode((string) $site->pairing_secret),
                $site->pairing_expires_at->getTimestamp()
            );
        }

        return view('sites.show', [
            'site' => $site,
            'pairingCode' => $pairingCode,
            'pluginVersion' => PluginPackage::version(),
            'events' => $site->events()->orderByDesc('seq')->limit(100)->get(),
            'jobs' => $site->jobs()->with('requester')->orderByDesc('id')->limit(15)->get(),
            'inventory' => $site->inventory ?? [],
            'commands' => self::MANUAL_COMMANDS,
            'updates' => collect($site->availableUpdates())->keyBy(fn ($u) => $u['type'] . ':' . $u['id']),
            'queued' => Updates::queuedIds($site),
            'updatesBlocked' => $site->updatesBlockedReason(),
            'pagespeed' => PagespeedResult::latestPerSite()->get($site->id, collect()),
            'lastUpdated' => self::lastUpdated($site),
            // Alleen een fout tonen als dat per strategie nog de nieuwste meting is.
            'pagespeedLastError' => $site->pagespeedResults()->where('measured_at', '>=', now()->subDay())->orderByDesc('id')->get()
                ->unique('strategy')->first(fn (PagespeedResult $r) => $r->error !== null),
            'pagespeedHistory' => $site->pagespeedResults()->where('strategy', 'mobile')->whereNull('error')
                ->where('measured_at', '>=', now()->subDays(120))->orderBy('measured_at')->pluck('performance')->filter(fn ($v) => $v !== null)->values()->all(),
        ]);
    }

    /**
     * Wanneer elke plugin en elk thema voor het laatst is bijgewerkt: uit het wijzigingslog, en
     * anders de bestandsdatum die de plugin (vanaf 0.3.0) in de inventaris meestuurt.
     *
     * @return array<string, \Carbon\CarbonInterface>
     */
    private static function lastUpdated(Site $site): array
    {
        $map = [];
        foreach (['plugins' => 'file', 'themes' => 'stylesheet'] as $list => $idKey) {
            foreach ((array) (($site->inventory ?? [])[$list] ?? []) as $item) {
                if (isset($item[$idKey]) && is_int($item['updated_at'] ?? null)) {
                    $map[rtrim($list, 's') . ':' . $item[$idKey]] = \Carbon\CarbonImmutable::createFromTimestamp($item['updated_at']);
                }
            }
        }
        $site->events()->whereIn('type', ['plugin.update', 'plugin.install', 'theme.update', 'theme.install'])->where('result', 'success')
            ->selectRaw('type, object, max(occurred_at) as at')->groupBy('type', 'object')->get()
            ->each(function ($row) use (&$map) {
                $key = strtok($row->type, '.') . ':' . $row->object;
                $at = \Carbon\CarbonImmutable::parse($row->at);
                if (!isset($map[$key]) || $at->greaterThan($map[$key])) {
                    $map[$key] = $at;
                }
            });

        return $map;
    }

    public function regeneratePairing(Site $site): RedirectResponse
    {
        if ($site->isPaired()) {
            return back()->withErrors(['pairing' => 'Deze site is al gekoppeld. Verwijder de site en voeg hem opnieuw toe om opnieuw te koppelen.']);
        }
        $this->openPairing($site);
        $site->save();
        Activity::log('pairing_regenerated', [], $site);

        return redirect()->route('sites.show', $site)->with('status', 'Nieuwe koppelcode aangemaakt (15 minuten geldig).');
    }

    public function queueCommand(Request $request, Site $site): RedirectResponse
    {
        $command = (string) $request->input('command');
        if (!array_key_exists($command, self::MANUAL_COMMANDS) || !$site->isPaired()) {
            abort(422);
        }
        $args = $command === 'site.inventory' ? ['refresh' => true] : [];
        $job = JobQueue::enqueue($site, $command, $args, $request->user());
        Activity::log('job_requested', ['job_id' => $job->job_id, 'command' => $command], $site);

        return redirect()->route('sites.show', $site)->with('status', self::MANUAL_COMMANDS[$command] . ' ingepland. Ververs de pagina over een paar seconden.');
    }

    public function destroy(Request $request, Site $site): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:' . $site->host]], ['confirm.in' => 'Typ de hostnaam van de site ter bevestiging.']);
        Activity::log('site_deleted', ['name' => $site->name, 'url' => $site->url, 'site_id' => $site->site_id], null);
        $site->delete();

        return redirect()->route('sites.index')->with('status', 'Site verwijderd. Ontkoppel de plugin ook op de site zelf.');
    }

    private function openPairing(Site $site): void
    {
        $site->pairing_secret = Base64Url::encode(Pairing::newSecret());
        $site->pairing_expires_at = now()->addMinutes((int) config('ocm.pairing_lifetime_minutes'));
    }
}
