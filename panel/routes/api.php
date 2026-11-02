<?php

use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\PairController;
use Illuminate\Support\Facades\Route;

// Aangeroepen door de client-plugin op klantsites. Geen sessies/CSRF; authenticatie via HMAC/Ed25519.
Route::post('/pair', PairController::class)->middleware('throttle:10,1');
Route::post('/sites/{siteId}/events', EventsController::class)
    ->where('siteId', 'site_[a-z0-9]{1,59}')
    ->middleware('throttle:240,1');
