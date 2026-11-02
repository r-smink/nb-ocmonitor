<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\PluginDownloadController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:20,1');
    Route::get('/login/2fa', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/login/2fa', [TwoFactorController::class, 'verify'])->middleware('throttle:20,1');
    Route::get('/login/2fa/instellen', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/login/2fa/instellen', [TwoFactorController::class, 'confirm'])->middleware('throttle:20,1');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', [SiteController::class, 'index'])->name('sites.index');
    Route::get('/sites/nieuw', [SiteController::class, 'create'])->name('sites.create')->can('manage-sites');
    Route::post('/sites', [SiteController::class, 'store'])->name('sites.store')->can('manage-sites');
    Route::get('/sites/{site}', [SiteController::class, 'show'])->name('sites.show');
    Route::post('/sites/{site}/koppelcode', [SiteController::class, 'regeneratePairing'])->name('sites.pairing')->can('manage-sites');
    Route::post('/sites/{site}/opdracht', [SiteController::class, 'queueCommand'])->name('sites.command')->can('manage-sites');
    Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy')->can('manage-sites');

    Route::get('/download/nb-ocmonitor-client.zip', PluginDownloadController::class)->name('plugin.download');
    Route::get('/activiteit', [ActivityController::class, 'index'])->name('activity.index');

    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::put('/account/wachtwoord', [AccountController::class, 'updatePassword'])->name('account.password');

    Route::middleware('can:manage-users')->group(function () {
        Route::get('/gebruikers', [UserController::class, 'index'])->name('users.index');
        Route::post('/gebruikers', [UserController::class, 'store'])->name('users.store');
        Route::put('/gebruikers/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/gebruikers/{user}/2fa-reset', [UserController::class, 'resetTwoFactor'])->name('users.reset-2fa');
        Route::delete('/gebruikers/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
