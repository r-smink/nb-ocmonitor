<?php

namespace App\Http\Controllers;

use App\Mail\AlertDigest;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\User;
use App\Ocm\Activity;
use App\Ocm\Alerts\AlertFilter;
use App\Ocm\Alerts\Channels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Meldingen: eigen voorkeuren (account), die van portalgebruikers en de gedeelde kanalen (admin). */
class AlertController extends Controller
{
    /** Instellingen → Meldingen: kanalen en recente verzendingen. */
    public function channels(): View
    {
        $all = Channels::all();

        return view('settings.alerts', [
            'channels' => collect(Channels::TYPES)->map(fn ($label, $type) => [
                'label' => $label,
                'configured' => Channels::hasUrl($type),
                'prefs' => AlertFilter::from($all[$type]['filter'] ?? ['enabled' => true])->prefs,
            ]),
            'secret' => Channels::webhookSecret(),
            'users' => User::query()->orderBy('name')->get(),
            'deliveries' => AlertDelivery::query()->with('alert.site')->orderByDesc('id')->limit(30)->get(),
            'open' => Alert::query()->with('site')->whereNull('resolved_at')->where('level', 'crit')->orderByDesc('opened_at')->limit(20)->get(),
        ]);
    }

    public function saveChannel(Request $request, string $type): RedirectResponse
    {
        abort_unless(isset(Channels::TYPES[$type]), 404);
        $data = $request->validate($this->filterRules() + ['url' => ['nullable', 'string', 'max:1000'], 'clear' => ['nullable', 'boolean']]);
        $url = trim((string) ($data['url'] ?? ''));
        if (empty($data['clear'])) {
            if ($url === '' && !Channels::hasUrl($type)) {
                return back()->withErrors(['url' => 'Vul de webhook-URL in.']);
            }
            if ($url !== '' && ($problem = Channels::urlProblem($url, $type))) {
                return back()->withErrors(['url' => $problem]);
            }
        }
        Channels::save($type, $url, !empty($data['clear']), AlertFilter::fromInput($data));
        Activity::log('alert_channel_updated', ['type' => $type, 'removed' => !empty($data['clear'])]);

        return back()->with('status', !empty($data['clear']) ? 'Kanaal verwijderd.' : 'Kanaal opgeslagen.');
    }

    public function testChannel(string $type): RedirectResponse
    {
        $channel = Channels::active()[$type] ?? null;
        abort_if($channel === null, 404);
        try {
            Channels::send($type, $channel['url'], $channel['secret'], [self::testItem()]);
        } catch (\Throwable $e) {
            return back()->withErrors(['channel' => 'Testbericht mislukt: ' . mb_substr($e->getMessage(), 0, 200)]);
        }

        return back()->with('status', 'Testbericht verstuurd naar ' . Channels::TYPES[$type] . '.');
    }

    public function editUser(User $user): View
    {
        return view('users.alerts', ['user' => $user, 'prefs' => AlertFilter::forUser($user)->prefs]);
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $user->alert_prefs = AlertFilter::fromInput($request->validate($this->filterRules()));
        $user->save();
        Activity::log('alert_prefs_updated', ['email' => $user->email]);

        return redirect()->route('users.index')->with('status', "Meldingen voor {$user->email} opgeslagen.");
    }

    public function updateAccount(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->alert_prefs = AlertFilter::fromInput($request->validate($this->filterRules()));
        $user->save();
        Activity::log('alert_prefs_updated', ['email' => $user->email]);

        return redirect()->to(route('account.show') . '#meldingen')->with('status', 'Je meldingen zijn opgeslagen.');
    }

    public function testAccount(Request $request): RedirectResponse
    {
        $user = $request->user();
        try {
            Mail::to($user->email)->send(new AlertDigest($user, [self::testItem()]));
        } catch (\Throwable $e) {
            return back()->withErrors(['mail' => 'Test-e-mail mislukt: ' . mb_substr($e->getMessage(), 0, 200)]);
        }

        return redirect()->to(route('account.show') . '#meldingen')->with('status', "Test-e-mail verstuurd naar {$user->email}.");
    }

    /** @return array<string, mixed> */
    private function filterRules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'min_level' => ['required', Rule::in(array_keys(AlertFilter::LEVELS))],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', Rule::in(array_keys(\App\Ocm\SiteIssues::CATEGORIES))],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer'],
            'customers' => ['nullable', 'array'],
            'customers.*' => ['integer'],
            'resolved' => ['nullable', 'boolean'],
            'quiet_from' => ['nullable', 'date_format:H:i'],
            'quiet_to' => ['nullable', 'date_format:H:i'],
            'quiet_crit' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private static function testItem(): array
    {
        return [
            'kind' => 'open', 'level' => 'crit', 'category' => 'beschikbaarheid', 'category_label' => 'Beschikbaarheid',
            'text' => 'Testmelding van OC Monitor: als je dit ziet, werken de meldingen.',
            'site' => ['id' => 'test', 'name' => 'Testsite', 'url' => config('app.url')],
            'opened_at' => now()->toIso8601String(), 'resolved_at' => null, 'duration' => '0 minuten',
            'link' => route('dashboard'),
        ];
    }
}
