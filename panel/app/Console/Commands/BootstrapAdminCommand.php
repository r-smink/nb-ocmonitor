<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Ocm\Activity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Maakt het eerste admin-account uit OCM_ADMIN_EMAIL/OCM_ADMIN_PASSWORD als er nog geen gebruikers zijn,
 * of reset dat account (wachtwoord + 2FA) als OCM_RESET_ADMIN=1. Draait bij elke start van de app-container.
 */
#[Signature('ocm:bootstrap-admin')]
#[Description('Maakt of herstelt het eerste admin-account vanuit .env')]
class BootstrapAdminCommand extends Command
{
    public function handle(): int
    {
        $email = (string) config('ocm.admin.email');
        $password = (string) config('ocm.admin.password');
        $reset = (bool) config('ocm.admin.reset');

        if (User::query()->count() > 0 && !$reset) {
            return self::SUCCESS;
        }
        if ($email === '' || strlen($password) < 12) {
            $this->error('Zet OCM_ADMIN_EMAIL en OCM_ADMIN_PASSWORD (minimaal 12 tekens) in .env voor het eerste admin-account.');
            return User::query()->count() > 0 ? self::SUCCESS : self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first() ?? new User(['email' => $email, 'name' => 'Beheerder']);
        $user->password = $password;
        $user->role = 'admin';
        $user->totp_secret = null;
        $user->totp_confirmed_at = null;
        $user->totp_last_timestep = null;
        $user->save();

        if ($reset) {
            Activity::log('admin_reset_from_env', ['email' => $email], null, $user);
            $this->warn("Account {$email} is gereset (wachtwoord uit .env, 2FA opnieuw instellen). Zet OCM_RESET_ADMIN weer op 0.");
        } else {
            Activity::log('admin_bootstrapped', ['email' => $email], null, $user);
            $this->info("Admin-account {$email} aangemaakt. Bij de eerste login stel je 2FA in.");
        }

        return self::SUCCESS;
    }
}
