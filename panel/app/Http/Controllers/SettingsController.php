<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Ocm\Activity;
use App\Ocm\AppSettings;
use App\Ocm\Backups;
use App\Ocm\Menu;
use App\Ocm\PluginRelease;
use App\Ocm\Vulnerabilities;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function show(): View
    {
        return view('settings.show', [
            'values' => AppSettings::all(),
            'status' => [
                'Signer (panel-sleutel)' => (string) config('ocm.panel_public_key') !== '',
                'Mail voor rapporten' => config('mail.default') !== 'log',
                'Backups in Spaces' => Backups::enabled(),
                'PageSpeed API-sleutel' => (string) config('ocm.pagespeed.api_key') !== '',
                'Wordfence API-sleutel' => Vulnerabilities::enabled(),
                'Ondertekende plugin-release' => PluginRelease::latest() !== null,
            ],
            'release' => PluginRelease::latest()['version'] ?? null,
            'feed' => Setting::get(Vulnerabilities::SETTING),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = ['menu' => ['nullable', 'array'], 'menu.*' => [Rule::in(array_keys(Menu::STATES))], 'clear' => ['nullable', 'array']];
        foreach (AppSettings::FIELDS as $key => [, $type, , $min, $max]) {
            $rules[$key] = match ($type) {
                'int' => ['nullable', 'integer', 'min:' . $min, 'max:' . $max],
                'float' => ['nullable', 'numeric', 'min:' . $min, 'max:' . $max],
                'bool' => ['nullable', Rule::in(['', '0', '1'])],
                'secret' => ['nullable', 'string', 'max:500'],
                default => ['nullable', 'string', 'max:' . $max],
            };
        }
        $data = $request->validate($rules);
        AppSettings::save($data, (array) ($data['menu'] ?? []), array_values(array_filter((array) ($data['clear'] ?? []), 'is_string')));
        Activity::log('settings_updated', ['menu' => $data['menu'] ?? [], 'fields' => array_keys(array_filter($data, fn ($v, $k) => $k !== 'menu' && $v !== null && !str_ends_with($k, 'api_key'), ARRAY_FILTER_USE_BOTH))]);

        return back()->with('status', 'Instellingen opgeslagen.');
    }
}
