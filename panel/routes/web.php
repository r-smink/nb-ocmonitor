<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LicenseController;
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

// Terugkeer van Google na "Koppelen met Google". Buiten de inlogcontrole: de browser stuurt het
// sessiecookie (SameSite=strict) niet mee bij een navigatie vanaf accounts.google.com. De controle
// zit in de eenmalige state, die op de server aan de beheerder is gekoppeld.
Route::get('/instellingen/google/terug', [\App\Http\Controllers\SiteToolsController::class, 'callback'])->name('analytics.callback')->middleware('throttle:20,1');

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
    Route::get('/updates/wachtrij', [UpdateController::class, 'queue'])->name('updates.queue');
    Route::get('/updates/automatisch',[\App\Http\Controllers\UpdateScheduleController::class, 'index'])->name('updates.schedules.index');
    Route::middleware('can:manage-sites')->group(function () {
        Route::get('/updates/automatisch/nieuw', [\App\Http\Controllers\UpdateScheduleController::class, 'create'])->name('updates.schedules.create');
        Route::post('/updates/automatisch', [\App\Http\Controllers\UpdateScheduleController::class, 'store'])->name('updates.schedules.store');
        Route::get('/updates/automatisch/{schedule}', [\App\Http\Controllers\UpdateScheduleController::class, 'edit'])->name('updates.schedules.edit');
        Route::put('/updates/automatisch/{schedule}', [\App\Http\Controllers\UpdateScheduleController::class, 'update'])->name('updates.schedules.update');
        Route::delete('/updates/automatisch/{schedule}', [\App\Http\Controllers\UpdateScheduleController::class, 'destroy'])->name('updates.schedules.destroy');
        Route::post('/updates/automatisch/{schedule}/nu', [\App\Http\Controllers\UpdateScheduleController::class, 'runNow'])->name('updates.schedules.run')->middleware('throttle:10,1');
    });

    Route::get('/klanten', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/onderhoud', [CustomerController::class, 'maintenance'])->name('maintenance.index');
    Route::get('/klanten/nieuw', [CustomerController::class, 'create'])->name('customers.create')->can('manage-sites');
    Route::post('/klanten', [CustomerController::class, 'store'])->name('customers.store')->can('manage-sites');
    Route::get('/klanten/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::get('/klanten/{customer}/bewerken', [CustomerController::class, 'edit'])->name('customers.edit')->can('manage-sites');
    Route::put('/klanten/{customer}', [CustomerController::class, 'update'])->name('customers.update')->can('manage-sites');
    Route::delete('/klanten/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy')->can('manage-sites');
    Route::post('/klanten/{customer}/onderhoud', [CustomerController::class, 'complete'])->name('customers.complete')->can('manage-sites');
    Route::post('/klanten/{customer}/onderhoud/verplaatsen', [CustomerController::class, 'move'])->name('customers.move')->can('manage-sites');
    Route::post('/klanten/{customer}/onderhoud/{run}/heropenen', [CustomerController::class, 'reopen'])->name('customers.reopen')->can('manage-sites');
    Route::post('/klanten/{customer}/updates', [CustomerController::class, 'startUpdates'])->name('customers.updates')->can('manage-sites')->middleware('throttle:20,1');
    Route::match(['get', 'post'], '/klanten/{customer}/rapport/bekijken', [CustomerController::class, 'report'])->name('customers.report')->middleware('throttle:30,1');
    Route::post('/klanten/{customer}/rapport', [CustomerController::class, 'sendReport'])->name('customers.report.send')->can('manage-sites')->middleware('throttle:10,1');

    Route::get('/wp-gebruikers', [WpUserController::class, 'index'])->name('wp-users.index');
    Route::post('/wp-gebruikers/verversen', [WpUserController::class, 'refresh'])->name('wp-users.refresh')->can('manage-sites')->middleware('throttle:10,1');
    Route::post('/wp-gebruikers/2fa', [WpUserController::class, 'twoFactor'])->name('wp-users.two-factor')->can('manage-sites')->middleware('throttle:10,1');
    Route::post('/sites/{site}/2fa', [WpUserController::class, 'twoFactor'])->name('sites.two-factor')->can('manage-sites')->middleware('throttle:20,1');
    Route::post('/sites/{site}/wp-gebruikers/{userId}', [WpUserController::class, 'action'])->whereNumber('userId')->name('wp-users.action')->can('manage-sites')->middleware('throttle:30,1');
    Route::post('/sites/{site}/backups/volledig', [SiteController::class, 'fullBackup'])->name('sites.backup.full')->can('manage-sites')->middleware('throttle:5,1');
    Route::post('/sites/{site}/backups/{backup}/terugzetten', [SiteController::class, 'restoreBackup'])->name('sites.backup.restore')->can('manage-sites')->middleware('throttle:10,1');
    Route::post('/sites/{site}/backups/opruimen', [SiteController::class, 'clearBackups'])->name('sites.backup.clear')->can('manage-sites')->middleware('throttle:20,1');
    Route::post('/klanten/{customer}/backups/opruimen', [CustomerController::class, 'clearBackups'])->name('customers.backup.clear')->can('manage-sites')->middleware('throttle:20,1');
    Route::delete('/sites/{site}/backups/{backup}',[SiteController::class, 'deleteBackup'])->name('sites.backup.delete')->can('manage-sites');
    Route::post('/sites/{site}/notities', [\App\Http\Controllers\SiteNoteController::class, 'store'])->name('sites.notes.store')->can('manage-sites');
    Route::post('/sites/{site}/notities/{note}/vastzetten', [\App\Http\Controllers\SiteNoteController::class, 'pin'])->name('sites.notes.pin')->can('manage-sites');
    Route::delete('/sites/{site}/notities/{note}', [\App\Http\Controllers\SiteNoteController::class, 'destroy'])->name('sites.notes.destroy')->can('manage-sites');
    Route::post('/sites/{site}/visueel/{check}/gezien', [SiteController::class, 'reviewVisual'])->name('sites.visual.review')->can('manage-sites');

    Route::get('/versies', [VersionController::class, 'index'])->name('versions.index');
    Route::get('/kapotte-links', [\App\Http\Controllers\SiteToolsController::class, 'links'])->name('links.index');
    Route::middleware('can:manage-sites')->group(function () {
        Route::post('/sites/{site}/schakelaars', [\App\Http\Controllers\SiteToolsController::class, 'tweaks'])->name('sites.tweaks')->middleware('throttle:20,1');
        Route::post('/sites/{site}/links', [\App\Http\Controllers\SiteToolsController::class, 'requestLinks'])->name('sites.links.request')->middleware('throttle:10,1');
        Route::post('/php-fouten/{error}/opgelost', [\App\Http\Controllers\SiteToolsController::class, 'resolveError'])->name('php-errors.resolve');
        Route::post('/kapotte-links/{link}/negeren', [\App\Http\Controllers\SiteToolsController::class, 'ignoreLink'])->name('links.ignore');
        Route::post('/sites/{site}/google', [\App\Http\Controllers\SiteToolsController::class, 'analytics'])->name('sites.analytics');
    });
    Route::middleware('can:manage-users')->group(function () {
        Route::post('/sites/{site}/inloggen', [\App\Http\Controllers\PanelLoginController::class, 'start'])->name('sites.login')->middleware('throttle:20,1');
        Route::get('/sites/{site}/inloggen/{job}', [\App\Http\Controllers\PanelLoginController::class, 'wait'])->name('sites.login.wait');
        Route::post('/instellingen/google/koppelen', [\App\Http\Controllers\SiteToolsController::class, 'connect'])->name('analytics.connect');
        Route::post('/instellingen/google/ontkoppelen', [\App\Http\Controllers\SiteToolsController::class, 'disconnect'])->name('analytics.disconnect');
        Route::post('/instellingen/google/vernieuwen', [\App\Http\Controllers\SiteToolsController::class, 'refreshGoogleLists'])->name('analytics.refresh');
    });
    Route::get('/kwetsbaarheden', [\App\Http\Controllers\VulnerabilityController::class, 'index'])->name('vulnerabilities.index');
    Route::get('/licenties', [LicenseController::class, 'index'])->name('licenses.index');
    Route::middleware('can:manage-sites')->group(function () {
        Route::post('/licenties/producten', [LicenseController::class, 'storeProduct'])->name('licenses.products.store');
        Route::put('/licenties/producten/{product}', [LicenseController::class, 'updateProduct'])->name('licenses.products.update');
        Route::delete('/licenties/producten/{product}', [LicenseController::class, 'destroyProduct'])->name('licenses.products.destroy');
        Route::post('/licenties/producten/{product}/sleutels', [LicenseController::class, 'storeLicense'])->name('licenses.store');
        Route::delete('/licenties/sleutels/{license}', [LicenseController::class, 'destroyLicense'])->name('licenses.destroy');
        Route::post('/licenties/producten/{product}/activeren', [LicenseController::class, 'activate'])->name('licenses.activate')->middleware('throttle:10,1');
        Route::post('/licenties/producten/{product}/activeren/{site}', [LicenseController::class, 'activate'])->name('licenses.activate.site')->middleware('throttle:30,1');
    });
    Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
    Route::post('/tags', [TagController::class, 'store'])->name('tags.store')->can('manage-sites');
    Route::put('/tags/{tag}', [TagController::class, 'update'])->name('tags.update')->can('manage-sites');
    Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy')->can('manage-sites');
    Route::post('/sites/{site}/tags', [TagController::class, 'site'])->name('sites.tags')->can('manage-sites');
    Route::post('/sites/{site}/server', [SiteController::class, 'server'])->name('sites.server')->can('manage-sites');
    Route::post('/sites/{site}/opschonen', [SiteController::class, 'cleanup'])->name('sites.cleanup')->can('manage-sites')->middleware('throttle:10,1');

    Route::get('/rapporten', [ReportController::class, 'index'])->name('reports.index');
    Route::put('/rapporten', [ReportController::class, 'update'])->name('reports.update')->can('manage-sites');
    Route::post('/rapporten/bestanden', [ReportController::class, 'upload'])->name('reports.upload')->can('manage-sites')->middleware('throttle:30,1');
    Route::delete('/rapporten/bestanden/{kind}', [ReportController::class, 'destroyAsset'])->name('reports.asset.destroy')->can('manage-sites');
    Route::get('/rapporten/voorbeeld', [ReportController::class, 'preview'])->name('reports.preview')->middleware('throttle:30,1');

    Route::get('/download/nb-ocmonitor-client.zip', PluginDownloadController::class)->name('plugin.download');
    Route::get('/bulk-acties', [\App\Http\Controllers\BulkController::class, 'index'])->name('bulk.index');
    Route::post('/bulk-acties', [\App\Http\Controllers\BulkController::class, 'run'])->name('bulk.run')->can('manage-sites')->middleware('throttle:20,1');
    Route::get('/activiteit', [ActivityController::class, 'index'])->name('activity.index');
    Route::get('/activiteit/export', [ActivityController::class, 'export'])->name('activity.export')->middleware('throttle:10,1');

    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::put('/account/wachtwoord', [AccountController::class, 'updatePassword'])->name('account.password');
    Route::put('/account/meldingen', [\App\Http\Controllers\AlertController::class, 'updateAccount'])->name('account.alerts');
    Route::post('/account/meldingen/test', [\App\Http\Controllers\AlertController::class, 'testAccount'])->name('account.alerts.test')->middleware('throttle:5,1');

    Route::middleware('can:manage-users')->group(function () {
        Route::get('/instellingen', [\App\Http\Controllers\SettingsController::class, 'show'])->name('settings.show');
        Route::put('/instellingen', [\App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update');
        Route::get('/instellingen/meldingen', [\App\Http\Controllers\AlertController::class, 'channels'])->name('settings.alerts');
        Route::put('/instellingen/meldingen/smtp', [\App\Http\Controllers\AlertController::class, 'saveSmtp'])->name('settings.smtp');
        Route::post('/instellingen/meldingen/smtp/test', [\App\Http\Controllers\AlertController::class, 'testSmtp'])->name('settings.smtp.test')->middleware('throttle:5,1');
        Route::put('/instellingen/meldingen/{type}', [\App\Http\Controllers\AlertController::class, 'saveChannel'])->whereIn('type', ['slack', 'teams', 'webhook'])->name('settings.alerts.channel');
        Route::post('/instellingen/meldingen/{type}/test', [\App\Http\Controllers\AlertController::class, 'testChannel'])->whereIn('type', ['slack', 'teams', 'webhook'])->name('settings.alerts.test')->middleware('throttle:10,1');
        Route::get('/instellingen/api', [\App\Http\Controllers\ApiTokenController::class, 'index'])->name('settings.api');
        Route::post('/instellingen/api', [\App\Http\Controllers\ApiTokenController::class, 'store'])->name('settings.api.store');
        Route::post('/instellingen/api/status', [\App\Http\Controllers\ApiTokenController::class, 'toggle'])->name('settings.api.toggle');
        Route::delete('/instellingen/api/{token}', [\App\Http\Controllers\ApiTokenController::class, 'destroy'])->name('settings.api.destroy');
        Route::get('/gebruikers', [UserController::class, 'index'])->name('users.index');
        Route::get('/gebruikers/{user}/meldingen', [\App\Http\Controllers\AlertController::class, 'editUser'])->name('users.alerts');
        Route::put('/gebruikers/{user}/meldingen', [\App\Http\Controllers\AlertController::class, 'updateUser'])->name('users.alerts.update');
        Route::post('/gebruikers', [UserController::class, 'store'])->name('users.store');
        Route::put('/gebruikers/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/gebruikers/{user}/2fa-reset', [UserController::class, 'resetTwoFactor'])->name('users.reset-2fa');
        Route::delete('/gebruikers/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
