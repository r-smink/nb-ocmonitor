<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Ocm\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Stap 1: e-mail + wachtwoord. Daarna is de gebruiker nog NIET ingelogd: eerst 2FA (TwoFactorController).
 */
class LoginController extends Controller
{
    public const PENDING_KEY = 'login.pending_user';
    public const PENDING_AT = 'login.pending_at';
    public const PENDING_MINUTES = 10;

    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
            'password' => ['required', 'string', 'max:500'],
        ]);

        $throttleKey = 'login:' . mb_strtolower($data['email']) . '|' . $request->ip();
        $ipKey = 'login-ip:' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $seconds = RateLimiter::tooManyAttempts($throttleKey, 5) ? RateLimiter::availableIn($throttleKey) : RateLimiter::availableIn($ipKey);
            return back()->withInput($request->only('email'))->withErrors(['email' => "Te veel pogingen. Probeer het over {$seconds} seconden opnieuw."]);
        }

        $user = User::query()->where('email', $data['email'])->first();
        // Altijd een hash-vergelijking, ook als de gebruiker niet bestaat (geen timing-verschil).
        $valid = $user !== null ? Hash::check($data['password'], $user->password) : (Hash::make($data['password']) && false);

        if ($user === null || !$valid) {
            RateLimiter::hit($throttleKey, 900);
            RateLimiter::hit($ipKey, 3600);
            Activity::log('login_failed', ['email' => mb_substr($data['email'], 0, 191)], null, $user);
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Onjuiste combinatie van e-mail en wachtwoord.']);
        }

        RateLimiter::clear($throttleKey);
        if (Hash::needsRehash($user->password)) {
            $user->password = $data['password'];
            $user->save();
        }

        $request->session()->regenerate();
        $request->session()->put(self::PENDING_KEY, $user->id);
        $request->session()->put(self::PENDING_AT, time());

        return redirect()->route($user->hasTwoFactor() ? 'two-factor.challenge' : 'two-factor.setup');
    }

    public function logout(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            Activity::log('logout');
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** Geeft de gebruiker terug die stap 1 heeft gehaald, of null als dat te lang geleden is. */
    public static function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::PENDING_KEY);
        $at = (int) $request->session()->get(self::PENDING_AT, 0);
        if ($id === null || time() - $at > self::PENDING_MINUTES * 60) {
            return null;
        }

        return User::query()->find($id);
    }
}
