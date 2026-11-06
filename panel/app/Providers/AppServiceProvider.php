<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Instellingen uit het panel (Instellingen) over de waarden uit .env heen.
        \App\Ocm\AppSettings::apply();
        \App\Ocm\MailSettings::apply();

        Gate::define('manage-sites', fn (User $user): bool => $user->canManageSites());
        Gate::define('manage-users', fn (User $user): bool => $user->isAdmin());

        Paginator::defaultView('partials.pagination');

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
