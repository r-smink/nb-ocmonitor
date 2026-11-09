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
        $all = \App\Models\Tag::filter(Site::query()->with(['tags', 'customer.tags', 'pinnedNotes'])->orderBy('name')->get(), $request->integer('tag') ?: null);
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

        // Indeling: één lijst, per klant of per tag.
        $layout = in_array($request->query('indeling'), ['klant', 'tag'], true) ? (string) $request->query('indeling') : 'lijst';
        $groups = match ($layout) {
            'klant' => $sites->groupBy(fn (Site $s) => $s->customer?->name ?? ($s->client_name ?: 'Zonder klant'))->sortKeys()
                ->sortBy(fn ($g, $name) => $name === 'Zonder klant' ? 1 : 0),
            'tag' => (function () use ($sites) {
                $out = [];
                foreach ($sites as $site) {
                    $tags = $site->allTags();
                    foreach ($tags->isEmpty() ? [null] : $tags as $tag) {
                        $out[$tag?->name ?? 'Zonder tag'][] = $site;
                    }
                }
                ksort($out);

                return collect($out)->map(fn ($list) => collect($list))->sortBy(fn ($g, $name) => $name === 'Zonder tag' ? 1 : 0);
            })(),
            default => collect(['' => $sites]),
        };

        return view('sites.index', ['groups' => $groups, 'sites' => $sites, 'counts' => $counts, 'q' => $q, 'layout' => $layout]);
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
        $site->load(['tags', 'customer.tags']);
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

        // Kwetsbaarheden van deze site, ernstigste eerst, met dezelfde actieknop als op de pagina Kwetsbaarheden.
        $vulns = collect();
        $security = [];
        if ($site->isPaired() && \App\Ocm\Menu::enabled('vulnerabilities')) {
            $open = Updates::openJobs(collect([$site]));
            $rank = ['crit' => 0, 'warn' => 1, 'info' => 2];
            $vulns = collect(\App\Ocm\Vulnerabilities::forSite($site))->map(function (array $m) use ($site, $open) {
                $issue = ['component' => ['type' => $m['component']['type'], 'slug' => $m['component']['slug']]];

                return $m + ['level' => $m['vulnerability']->level(), 'action' => $m['fixed'] ? \App\Ocm\IssueActions::for($issue, $site, $open, request()->user()) : null];
            })->sortBy(fn ($m) => $rank[$m['level']] . sprintf('%03d', 100 - (int) (($m['vulnerability']->cvss_score ?? 0) * 10)))->values();
            foreach ($vulns as $m) {
                if ($m['fixed'] !== null) {
                    $security[$m['component']['type'] . ':' . strtolower((string) $m['component']['slug'])] = true;
                }
            }
        }

        return view('sites.show', [
            'site' => $site,
            'vulns' => $vulns,
            'security' => $security,
            'pairingCode' => $pairingCode,
            'pluginVersion' => PluginPackage::version(),
            'events' => $site->events()->orderByDesc('seq')->limit(100)->get(),
            'jobs' => $site->jobs()->with('requester')->orderByDesc('id')->limit(15)->get(),
            'inventory' => $site->inventory ?? [],
            'commands' => self::MANUAL_COMMANDS,
            'updates' => collect($site->availableUpdates())->keyBy(fn ($u) => $u['type'] . ':' . $u['id']),
            'risks' => $site->isPaired() ? \App\Ocm\UpdateRisk::forSite($site) : [],
            'trends' => $site->isPaired() ? \App\Ocm\SiteMetrics::series($site) : [],
            'queued' => Updates::queuedIds($site),
            'updatesBlocked' => $site->updatesBlockedReason(),
            'pagespeed' => PagespeedResult::latestPerSite()->get($site->id, collect()),
            'lastUpdated' => self::lastUpdated($site),
            'uptime' => \App\Ocm\Monitoring\Monitor::stats($site, \Carbon\CarbonImmutable::now()->subDays(30), \Carbon\CarbonImmutable::now()),
            'monthly' => \App\Ocm\Monitoring\Incidents::monthly($site, 6),
            'incidents' => \App\Models\UptimeIncident::query()->where('site_id', $site->id)->orderByDesc('started_at')->limit(20)->get(),
            'visual' => $site->visualChecks()->latest('id')->first(),
            'backups' => \App\Models\Backup::query()->where('site_id', $site->id)->where(fn ($q) => $q->whereIn('status', ['pending', 'ready', 'restoring'])->orWhere('created_at', '>=', now()->subDays(7)))->latest('id')->limit(10)->get(),
            'wpUsers' => collect((array) (($site->wp_users ?? [])['users'] ?? []))->map(fn ($u) => $u + ['site' => $site]),
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

    public function reviewVisual(Request $request, Site $site, \App\Models\VisualCheck $check): RedirectResponse
    {
        abort_unless($check->site_id === $site->id, 404);
        $check->update(['reviewed_at' => now(), 'reviewed_by' => $request->user()->id]);
        \App\Ocm\Backups::deleteForVisualCheck($check);
        Activity::log('visual_reviewed', ['check' => $check->id, 'percent' => $check->diff_percent], $site);

        return back()->with('status', 'Visuele controle gemarkeerd als bekeken.');
    }

    public const CLEANUP_ITEMS = [
        'revisions' => 'Revisies ouder dan 30 dagen',
        'auto_drafts' => 'Automatische concepten ouder dan 7 dagen',
        'trash' => 'Prullenbak ouder dan 30 dagen',
        'spam' => 'Spamreacties',
        'trashed_comments' => 'Reacties in de prullenbak',
        'expired_transients' => 'Verlopen transients',
        'orphan_postmeta' => 'Losse berichtgegevens',
        'orphan_commentmeta' => 'Losse reactiegegevens',
    ];

    public function cleanup(Request $request, Site $site): RedirectResponse
    {
        $data = $request->validate(['items' => ['required', 'array'], 'items.*' => ['string', \Illuminate\Validation\Rule::in(array_keys(self::CLEANUP_ITEMS))]]);
        if ($reason = $site->cleanupBlockedReason()) {
            return back()->withErrors(['cleanup' => $reason]);
        }
        if (\App\Models\SiteJob::query()->where('site_id', $site->id)->where('command', 'db.cleanup')->whereIn('status', ['pending', 'running'])->exists()) {
            return back()->with('status', 'Er staat al een opschoning in de wachtrij.');
        }
        \App\Ocm\Updates::queueCleanup($site, array_values(array_unique($data['items'])), $request->user());

        return back()->with('status', 'Opschonen staat in de wachtrij' . (\App\Ocm\Backups::enabled() && \App\Ocm\Backups::supported($site) ? ', na een backup van de database.' : '.'));
    }

    public function restoreBackup(Request $request, Site $site, \App\Models\Backup $backup): RedirectResponse
    {
        abort_unless($backup->site_id === $site->id, 404);
        $data = $request->validate(['database' => ['nullable', 'boolean'], 'confirm_orders' => ['nullable', 'boolean']]);
        if ($backup->status !== 'ready' || $backup->deleted_at !== null) {
            return back()->withErrors(['backup' => 'Deze backup kan niet (meer) teruggezet worden.']);
        }
        $parts = ['files'];
        if (!empty($data['database'])) {
            if ($backup->woocommerce && empty($data['confirm_orders'])) {
                return back()->withErrors(['backup' => 'Dit is een webshop: bevestig dat bestellingen en klantgegevens van na de backup verloren gaan.']);
            }
            $parts[] = 'db';
        }
        \App\Ocm\Backups::restore($backup, $parts, $request->user());

        return back()->with('status', 'Terugzetten van de backup van ' . $backup->created_at->timezone('Europe/Amsterdam')->format('d-m H:i') . ' staat in de wachtrij (' . (in_array('db', $parts, true) ? 'bestanden en database' : 'alleen bestanden') . ').');
    }

    public function fullBackup(Request $request, Site $site): RedirectResponse
    {
        if (!\App\Ocm\Backups::enabled() || !\App\Ocm\Backups::supportsFull($site)) {
            return back()->withErrors(['backup' => 'Volledige backups kunnen vanaf client-plugin 0.8.0, met Spaces ingesteld.']);
        }
        if (\App\Models\Backup::query()->where('site_id', $site->id)->where('status', 'pending')->get()->contains(fn ($b) => !empty($b->components['full']))) {
            return back()->with('status', 'Er wordt al een volledige backup gemaakt.');
        }
        \App\Ocm\Backups::full($site, $request->user());

        return back()->with('status', 'Volledige backup staat in de wachtrij. Updates in de komende ' . config('ocm.backups.full_reuse_hours') . ' uur gebruiken deze backup.');
    }

    public function deleteBackup(Site $site, \App\Models\Backup $backup): RedirectResponse
    {
        abort_unless($backup->site_id === $site->id, 404);
        \App\Ocm\Backups::delete($backup, 'handmatig');

        return back()->with('status', 'Backup verwijderd.');
    }

    /** Alles gecontroleerd en in orde: alle back-ups van deze site opruimen (niet die van lopende updates). */
    public function clearBackups(Request $request, Site $site): RedirectResponse
    {
        $n = \App\Ocm\Backups::deleteChecked($site, $request->user());

        return back()->with('status', $n > 0 ? "{$n} back-up(s) verwijderd." : 'Geen back-ups om op te ruimen.');
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
        $allowed = self::MANUAL_COMMANDS + ['security.integrity' => 'Integriteitscontrole'];
        if (!array_key_exists($command, $allowed) || !$site->isPaired()) {
            abort(422);
        }
        if ($command === 'security.integrity' && !$site->supports('0.9.0')) {
            return back()->withErrors(['command' => 'De integriteitscontrole kan vanaf client-plugin 0.9.0.']);
        }
        $args = $command === 'site.inventory' ? ['refresh' => true] : [];
        $job = JobQueue::enqueue($site, $command, $args, $request->user());
        Activity::log('job_requested', ['job_id' => $job->job_id, 'command' => $command], $site);

        return redirect()->route('sites.show', $site)->with('status', $allowed[$command] . ' ingepland. Ververs de pagina over een paar seconden.');
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
