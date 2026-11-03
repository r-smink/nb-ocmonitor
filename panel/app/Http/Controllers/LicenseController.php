<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Models\LicenseProduct;
use App\Models\Site;
use App\Models\SiteJob;
use App\Models\User;
use App\Ocm\Activity;
use App\Ocm\JobQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Licenties van betaalde plugins: zelf producten en sleutels toevoegen, per site de status zien en
 * (waar mogelijk) met één klik activeren. Sleutels staan versleuteld in de database.
 */
class LicenseController extends Controller
{
    public function index(): View
    {
        $sites = Site::query()->whereNotNull('public_key')->orderBy('name')->get();
        $products = LicenseProduct::query()->with('licenses')->orderBy('name')->get();
        $open = SiteJob::query()->where('command', 'license.activate')->whereIn('status', ['pending', 'running'])->get(['site_id', 'args'])
            ->map(fn (SiteJob $j) => $j->site_id . ':' . (($j->args ?? [])['adapter'] ?? ''))->all();

        return view('licenses.index', [
            'products' => $products->map(fn (LicenseProduct $p) => ['product' => $p, 'installations' => $p->installations($sites)]),
            'presets' => LicenseProduct::PRESETS,
            'open' => $open,
        ]);
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $preset = LicenseProduct::PRESETS[(string) $request->input('preset')] ?? [];
        $request->merge(array_filter(array_merge($preset, array_filter($request->only(['name', 'plugin_file', 'method', 'option_name', 'settings_path']), fn ($v) => $v !== null && $v !== ''))));
        $data = $this->validateProduct($request);
        $product = LicenseProduct::query()->create($data + ['status_key' => $preset['status_key'] ?? self::statusKey($data['method'])]);
        Activity::log('license_product_created', ['product' => $product->name]);

        return back()->with('status', "{$product->name} toegevoegd. Voeg nu een licentiesleutel toe.");
    }

    public function updateProduct(Request $request, LicenseProduct $product): RedirectResponse
    {
        $data = $this->validateProduct($request);
        $product->update($data + ['status_key' => $product->status_key ?? self::statusKey($data['method'])]);

        return back()->with('status', 'Product opgeslagen.');
    }

    public function destroyProduct(LicenseProduct $product): RedirectResponse
    {
        Activity::log('license_product_deleted', ['product' => $product->name]);
        $product->delete();

        return back()->with('status', 'Product en sleutels verwijderd.');
    }

    public function storeLicense(Request $request, LicenseProduct $product): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'key' => ['required', 'string', 'regex:/^[A-Za-z0-9._\-=+\/]{4,200}$/'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['key.regex' => 'Een licentiesleutel bestaat uit letters, cijfers en - _ . = + /.']);
        $product->licenses()->create($data);
        Activity::log('license_added', ['product' => $product->name, 'label' => $data['label']]);

        return back()->with('status', 'Licentie toegevoegd.');
    }

    public function destroyLicense(License $license): RedirectResponse
    {
        Activity::log('license_deleted', ['product' => $license->product->name, 'label' => $license->label]);
        $license->delete();

        return back()->with('status', 'Licentie verwijderd uit het panel. Op de sites blijft hij actief tot je hem daar deactiveert.');
    }

    /** Op één site, of (zonder site) op alle sites met de plugin en zonder actieve licentie. */
    public function activate(Request $request, LicenseProduct $product, ?Site $site = null): RedirectResponse
    {
        $data = $request->validate(['license' => ['required', 'integer', Rule::exists('licenses', 'id')->where('license_product_id', $product->id)]]);
        if (!$product->automatic()) {
            return back()->withErrors(['license' => 'Dit product activeer je handmatig in WP-admin.']);
        }
        $license = License::query()->findOrFail($data['license']);
        $targets = $site
            ? collect([$site])
            : $product->installations(Site::query()->whereNotNull('public_key')->get())->filter(fn ($i) => $i['status'] !== true)->pluck('site');

        $queued = 0;
        $skipped = [];
        foreach ($targets as $target) {
            if ($target->plugin_version === null || version_compare($target->plugin_version, '0.7.0', '<') || !$target->hasScope('licenses')) {
                $skipped[] = $target->name;
                continue;
            }
            $this->queue($product, $license, $target, $request->user());
            $queued++;
        }

        return back()->with('status', "Activeren van {$product->name} staat in de wachtrij voor {$queued} site(s)."
            . ($skipped !== [] ? ' Overgeslagen (client-plugin ouder dan 0.7.0): ' . implode(', ', $skipped) . '.' : ''));
    }

    private function queue(LicenseProduct $product, License $license, Site $site, ?User $user): void
    {
        $args = ['adapter' => $product->method, 'key' => (string) $license->key] + ($product->method === 'option' ? ['option' => (string) $product->option_name] : []);
        $job = JobQueue::enqueue($site, 'license.activate', $args, $user, false);
        Activity::log('license_activate_requested', ['job_id' => $job->job_id, 'product' => $product->name, 'license' => $license->label], $site, $user);
    }

    /** @return array<string, mixed> */
    private function validateProduct(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'plugin_file' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)?\.php$/'],
            'method' => ['required', Rule::in(array_keys(LicenseProduct::METHODS))],
            'option_name' => ['nullable', 'required_if:method,option', 'string', 'max:160', 'regex:/^[a-z0-9_-]{0,80}licen[cs]e[a-z0-9_-]{0,80}$/'],
            'settings_path' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9._\-\/?=&]+$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'plugin_file.regex' => 'Gebruik het pluginbestand zoals in de inventaris, bijv. elementor-pro/elementor-pro.php.',
            'option_name.regex' => 'De optienaam moet "license" of "licence" bevatten (kleine letters, cijfers, - en _).',
        ]);
    }

    private static function statusKey(string $method): ?string
    {
        return in_array($method, ['elementor_pro', 'acf_pro'], true) ? $method : null;
    }
}
