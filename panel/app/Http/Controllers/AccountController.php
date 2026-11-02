<?php

namespace App\Http\Controllers;

use App\Ocm\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('account.show', ['user' => $request->user()]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ], ['current_password.current_password' => 'Het huidige wachtwoord klopt niet.']);

        $user = $request->user();
        $user->password = $request->input('password');
        $user->save();
        // Andere sessies van deze gebruiker afmelden.
        Auth::logoutOtherDevices($request->input('password'));
        Activity::log('password_changed');

        return back()->with('status', 'Wachtwoord gewijzigd. Andere sessies zijn afgemeld.');
    }
}
