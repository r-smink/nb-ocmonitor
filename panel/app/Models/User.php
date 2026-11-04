<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token', 'totp_secret'])]
class User extends Authenticatable
{
    public const ROLES = [
        'admin' => 'Admin',
        'beheerder' => 'Beheerder',
        'viewer' => 'Alleen lezen',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'alert_prefs' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function canManageSites(): bool
    {
        return in_array($this->role, ['admin', 'beheerder'], true);
    }

    public function hasTwoFactor(): bool
    {
        return $this->totp_confirmed_at !== null && $this->totp_secret !== null;
    }
}
