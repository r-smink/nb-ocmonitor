<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PageSpeedController;
use App\Http\Controllers\PluginDownloadController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VersionController;
use App\Http\Controllers\WpUserController;
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

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
    Route::get('/sites/nieuw', [SiteController::class, 'create'])->name('sites.create')->can('manage-sites');
    Route::post('/sites', [SiteController::class, 'store'])->name('sites.store')->can('manage-sites');
    Route::get('/sites/{site}', [SiteController::class, 'show'])->name('sites.show');
    Route::post('/sites/{site}/koppelcode', [SiteController::class, 'regeneratePairing'])->name('sites.pairing')->can('manage-sites');
    Route::post('/sites/{site}/opdracht', [SiteController::class, 'queueCommand'])->name('sites.command')->can('manage-sites');
    Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy')->can('manage-sites');
    Route::post('/sites/{site}/updates', [UpdateController::class, 'site'])->name('sites.updates')->can('manage-sites')->middleware('throttle:60,1');
    Route::post('/sites/{site}/pagespeed', [PageSpeedController::class, 'request'])->name('sites.pagespeed')->can('manage-sites')->middleware('throttle:30,1');

    Route::get('/updates', [UpdateController::class, 'index'])->name('updates.index');
    Route::post('/updates', [UpdateController::class, 'store'])->name('updates.store')->can('manage-sites')->middleware('throttle:30,1');
    Route::get('/pagespeed', [PageSpeedController::class, 'index'])->name('pagespeed.index');

    Route::get('/klanten', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/klanten/nieuw', [CustomerController::class, 'create'])->name('customers.create')->can('manage-sites');
    Route::post('/klanten', [CustomerController::class, 'store'])->name('customers.store')->can('manage-sites');
    Route::get('/klanten/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::get('/klanten/{customer}/bewerken', [CustomerController::class, 'edit'])->name('customers.edit')->can('manage-sites');
    Route::put('/klanten/{customer}', [CustomerController::class, 'update'])->name('customers.update')->can('manage-sites');
    Route::delete('/klanten/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy')->can('manage-sites');
    Route::post('/klanten/{customer}/onderhoud', [CustomerController::class, 'complete'])->name('customers.complete')->can('manage-sites');
    Route::post('/klanten/{customer}/onderhoud/{run}/heropenen', [CustomerController::class, 'reopen'])->name('customers.reopen')->can('manage-sites');
    Route::post('/klanten/{customer}/updates', [CustomerController::class, 'startUpdates'])->name('customers.updates')->can('manage-sites')->middleware('throttle:20,1');
    Route::match(['get', 'post'], '/klanten/{customer}/rapport/bekijken', [CustomerController::class, 'report'])->name('customers.report')->middleware('throttle:30,1');
    Route::post('/klanten/{customer}/rapport', [CustomerController::class, 'sendReport'])->name('customers.report.send')->can('manage-sites')->middleware('throttle:10,1');

    Route::get('/wp-gebruikers', [WpUserController::class, 'index'])->name('wp-users.index');
    Route::post('/wp-gebruikers/verversen', [WpUserController::class, 'refresh'])->name('wp-users.refresh')->can('manage-sites')->middleware('throttle:10,1');
    Route::post('/wp-gebruikers/2fa', [WpUserController::class, 'twoFactor'])->name('wp-users.two-factor')->can('manage-sites')->middleware('throttle:10,1');
    Route::post('/sites/{site}/2fa', [WpUserController::class, 'twoFactor'])->name('sites.two-factor')->can('manage-sites')->middleware('throttle:20,1');
    Route::post('/sites/{site}/wp-gebruikers/{userId}', [WpUserController::class, 'action'])->whereNumber('userId')->name('wp-users.action')->can('manage-sites')->middleware('throttle:30,1');
    Route::post('/sites/{site}/backups/{backup}/terugzetten', [SiteController::class, 'restoreBackup'])->name('sites.backup.restore')->can('manage-sites')->middleware('throttle:10,1');
    Route::delete('/sites/{site}/backups/{backup}', [SiteController::class, 'deleteBackup'])->name('sites.backup.delete')->can('manage-sites');
    Route::post('/sites/{site}/visueel/{check}/gezien', [SiteController::class, 'reviewVisual'])->name('sites.visual.review')->can('manage-sites');

    Route::get('/versies', [VersionController::class, 'index'])->name('versions.index');
    Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
    Route::post('/tags', [TagController::class, 'store'])->name('tags.store')->can('manage-sites');
    Route::put('/tags/{tag}', [TagController::class, 'update'])->name('tags.update')->can('manage-sites');
    Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy')->can('manage-sites');
    Route::post('/sites/{site}/tags', [TagController::class, 'site'])->name('sites.tags')->can('manage-sites');
    Route::post('/sites/{site}/opschonen', [SiteController::class, 'cleanup'])->name('sites.cleanup')->can('manage-sites')->middleware('throttle:10,1');

    Route::get('/rapporten', [ReportController::class, 'index'])->name('reports.index');
    Route::put('/rapporten', [ReportController::class, 'update'])->name('reports.update')->can('manage-sites');
    Route::post('/rapporten/bestanden', [ReportController::class, 'upload'])->name('reports.upload')->can('manage-sites')->middleware('throttle:30,1');
    Route::delete('/rapporten/bestanden/{kind}', [ReportController::class, 'destroyAsset'])->name('reports.asset.destroy')->can('manage-sites');
    Route::get('/rapporten/voorbeeld', [ReportController::class, 'preview'])->name('reports.preview')->middleware('throttle:30,1');

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
