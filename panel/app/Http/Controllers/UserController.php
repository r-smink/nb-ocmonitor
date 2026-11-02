<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Ocm\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Alleen voor admins (route-middleware can:manage-users). */
class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::query()->orderBy('name')->get(), 'roles' => User::ROLES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'password' => ['required', Password::min(12)],
        ]);
        $user = User::query()->create($data);
        Activity::log('user_created', ['email' => $user->email, 'role' => $user->role]);

        return back()->with('status', "Account {$user->email} aangemaakt. Bij de eerste login stelt de gebruiker 2FA in.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(User::ROLES))]]);
        if ($user->is($request->user()) && $data['role'] !== 'admin') {
            return back()->withErrors(['role' => 'Je kunt je eigen admin-rol niet afnemen.']);
        }
        $old = $user->role;
        $user->role = $data['role'];
        $user->save();
        Activity::log('user_role_changed', ['email' => $user->email, 'from' => $old, 'to' => $user->role]);

        return back()->with('status', 'Rol aangepast.');
    }

    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        $user->totp_secret = null;
        $user->totp_confirmed_at = null;
        $user->totp_last_timestep = null;
        $user->save();
        Activity::log('two_factor_reset', ['email' => $user->email]);

        return back()->with('status', "2FA van {$user->email} is gereset. Bij de volgende login stelt de gebruiker die opnieuw in.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'Je kunt je eigen account niet verwijderen.']);
        }
        Activity::log('user_deleted', ['email' => $user->email]);
        $user->delete();

        return back()->with('status', 'Account verwijderd.');
    }
}
