<?php

namespace App\Http\Controllers;

use App\Models\BrokenLink;
use App\Models\LinkScan;
use App\Models\Site;
use App\Models\Tag;
use App\Ocm\Activity;
use App\Ocm\GoogleApi;
use App\Ocm\JobQueue;
use App\Ocm\TweakCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Schakelaars, kapotte links en de koppeling met Google per site. */
class SiteToolsController extends Controller
{
    public function tweaks(Request $request, Site $site): RedirectResponse
    {
        if ($reason = TweakCatalog::blockedReason($site)) {
            return back()->withErrors(['tweaks' => $reason]);
        }
        $tweaks = TweakCatalog::fromForm($request->all());
        $job = JobQueue::enqueue($site, 'tweaks.set', ['tweaks' => $tweaks], $request->user(), false);
        Activity::log('tweaks_requested', ['job_id' => $job->job_id, 'tweaks' => $tweaks], $site);

        return back()->with('status', 'Schakelaars worden op de site ingesteld.');
    }

    public function links(Request $request): View
    {
        $sites = Tag::filter(Site::query()->with(['tags', 'customer.tags'])->whereNotNull('public_key')->orderBy('name')->get(), $request->integer('tag') ?: null);
        $showIgnored = $request->boolean('genegeerd');
        $links = BrokenLink::query()->with('site')->whereIn('site_id', $sites->pluck('id'))
            ->when(!$showIgnored, fn ($q) => $q->whereNull('ignored_at'))
            ->orderByDesc('internal')->orderBy('site_id')->orderBy('url')->limit(1000)->get();
        $scans = LinkScan::query()->whereIn('site_id', $sites->pluck('id'))->where('status', '!=', 'running')
            ->orderByDesc('id')->get()->unique('site_id')->keyBy('site_id');

        return view('links.index', ['links' => $links, 'sites' => $sites, 'scans' => $scans, 'showIgnored' => $showIgnored]);
    }

    public function requestLinks(Site $site): RedirectResponse
    {
        $site->forceFill(['links_requested_at' => now()])->save();

        return back()->with('status', 'Linkcontrole aangevraagd. Die start binnen 10 minuten en duurt een paar minuten.');
    }

    public function ignoreLink(BrokenLink $link): RedirectResponse
    {
        $link->update(['ignored_at' => $link->ignored_at ? null : now()]);

        return back()->with('status', $link->ignored_at ? 'Link genegeerd.' : 'Link wordt weer gemeld.');
    }

    public function analytics(Request $request, Site $site): RedirectResponse
    {
        $ga4 = $request->input('ga4_property') ?: null;
        $gsc = $request->input('gsc_property') ?: null;
        try {
            $request->validate([
                'ga4_property' => ['nullable', Rule::in(array_keys(GoogleApi::ga4Properties()))],
                'gsc_property' => ['nullable', Rule::in(GoogleApi::gscSites())],
            ]);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['google' => $e->getMessage()]);
        }
        $site->forceFill(['ga4_property' => $ga4, 'gsc_property' => $gsc])->save();
        \Illuminate\Support\Facades\DB::table('analytics_snapshots')->where('site_id', $site->id)->delete();
        Activity::log('analytics_linked', ['ga4' => $ga4, 'gsc' => $gsc], $site);

        return back()->with('status', 'Google-koppeling van de site opgeslagen.');
    }

    // ---------------------------------------------------------------- OAuth (Instellingen)

    public function connect(Request $request): RedirectResponse
    {
        if (!GoogleApi::configured()) {
            return back()->withErrors(['google' => 'Vul eerst de OAuth-client-ID en het geheim in.']);
        }
        // State op de server (niet in de sessie): bij de terugkeer van Google ontbreekt het sessiecookie.
        $state = bin2hex(random_bytes(24));
        Cache::put('ocm.google.state.' . $state, $request->user()->id, now()->addMinutes(15));

        return redirect()->away(GoogleApi::authUrl($state));
    }

    /**
     * Terugkeer van Google. Er is hier geen sessie (zie routes/web.php); de eenmalige state bepaalt
     * welke beheerder het koppelen startte. Daarna een pagina die binnen het panel doorstuurt, zodat
     * de browser het sessiecookie weer meestuurt.
     */
    public function callback(Request $request): \Illuminate\Http\Response
    {
        $state = preg_replace('/[^a-f0-9]/', '', (string) $request->query('state'));
        $userId = $state !== '' ? Cache::pull('ocm.google.state.' . $state) : null;
        $user = $userId ? \App\Models\User::query()->find($userId) : null;

        if ($user === null || !$user->isAdmin()) {
            return self::done(false, 'Ongeldig of verlopen antwoord van Google. Start het koppelen opnieuw vanuit Instellingen.');
        }
        if ($request->filled('error')) {
            return self::done(false, 'Google: ' . mb_substr((string) $request->query('error'), 0, 100));
        }
        try {
            $email = GoogleApi::connect((string) $request->query('code'));
        } catch (\Throwable $e) {
            return self::done(false, $e->getMessage());
        }
        Activity::log('google_connected', ['email' => $email], null, $user);

        return self::done(true, "Google gekoppeld als {$email}.");
    }

    private static function done(bool $ok, string $message): \Illuminate\Http\Response
    {
        return response()->view('settings.google-done', ['ok' => $ok, 'message' => $message], $ok ? 200 : 400);
    }

    public function disconnect(): RedirectResponse
    {
        GoogleApi::disconnect();
        Activity::log('google_disconnected');

        return back()->with('status', 'Google ontkoppeld.');
    }

    public function refreshGoogleLists(): RedirectResponse
    {
        Cache::forget('ocm.google.ga4');
        Cache::forget('ocm.google.gsc');

        return back()->with('status', 'Lijsten met properties worden opnieuw opgehaald.');
    }
}
