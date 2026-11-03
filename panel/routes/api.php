<?php

use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\PairController;
use App\Ocm\PluginRelease;
use Illuminate\Support\Facades\Route;

// Aangeroepen door de client-plugin op klantsites. Geen sessies/CSRF; authenticatie via HMAC/Ed25519.
Route::post('/pair', PairController::class)->middleware('throttle:10,1');

// Ondertekende plugin-release voor zelf bijwerken. Openbaar: de code is niet geheim en de plugin
// controleert de handtekening met de release-sleutel, die niet op deze server staat.
Route::get('/plugin/nb-ocmonitor-client-{version}.zip', function (string $version) {
    $release = PluginRelease::latest();
    abort_unless($release !== null && $release['version'] === $version, 404);

    return response()->download($release['path'], $release['file'], ['Content-Type' => 'application/zip']);
})->where('version', '[0-9]+\.[0-9]+\.[0-9]+')->middleware('throttle:60,1');
Route::post('/sites/{siteId}/events', EventsController::class)
    ->where('siteId', 'site_[a-z0-9]{1,59}')
    ->middleware('throttle:240,1');
