<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Ocm\Activity;
use App\Ocm\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Stap 2: verplichte TOTP. Wie nog geen 2FA heeft, moet die eerst instellen; zonder 2FA kom je er niet in.
 */
class TwoFactorController extends Controller
{
    private const SETUP_SECRET = 'login.setup_secret';

    public function __construct(private readonly TwoFactor $twoFactor)
    {
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        $user = LoginController::pendingUser($request);
        if ($user === null || !$user->hasTwoFactor()) {
            return redirect()->route('login');
        }

        return view('auth.two-factor');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = LoginController::pendingUser($request);
        if ($user === null || !$user->hasTwoFactor()) {
            return redirect()->route('login');
        }
        if ($this->tooManyAttempts($user)) {
            return back()->withErrors(['code' => 'Te veel pogingen. Wacht even en probeer opnieuw.']);
        }

        $code = (string) $request->input('code', '');
        if (!$this->twoFactor->verifyUser($user, $code)) {
            RateLimiter::hit($this->throttleKey($user), 300);
            Activity::log('login_2fa_failed', [], null, $user);
            return back()->withErrors(['code' => 'Ongeldige of al gebruikte code.']);
        }

        return $this->completeLogin($request, $user);
    }

    public function setup(Request $request): View|RedirectResponse
    {
        $user = LoginController::pendingUser($request);
        if ($user === null) {
            return redirect()->route('login');
        }
        if ($user->hasTwoFactor()) {
            return redirect()->route('two-factor.challenge');
        }

        $secret = $request->session()->get(self::SETUP_SECRET) ?? $this->twoFactor->newSecret();
        $request->session()->put(self::SETUP_SECRET, $secret);

        return view('auth.two-factor-setup', [
            'qr' => $this->twoFactor->qrSvg($user, $secret),
            'secret' => trim(chunk_split($secret, 4, ' ')),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = LoginController::pendingUser($request);
        $secret = $request->session()->get(self::SETUP_SECRET);
        if ($user === null || $secret === null || $user->hasTwoFactor()) {
            return redirect()->route('login');
        }
        if ($this->tooManyAttempts($user)) {
            return back()->withErrors(['code' => 'Te veel pogingen. Wacht even en probeer opnieuw.']);
        }

        $timestep = $this->twoFactor->verifySecret($secret, (string) $request->input('code', ''));
        if ($timestep === null) {
            RateLimiter::hit($this->throttleKey($user), 300);
            return back()->withErrors(['code' => 'De code klopt niet. Controleer de tijd op je telefoon en probeer opnieuw.']);
        }

        $user->totp_secret = $secret;
        $user->totp_confirmed_at = now();
        $user->totp_last_timestep = $timestep;
        $user->save();
        $request->session()->forget(self::SETUP_SECRET);
        Activity::log('two_factor_enabled', [], null, $user);

        return $this->completeLogin($request, $user);
    }

    private function completeLogin(Request $request, User $user): RedirectResponse
    {
        RateLimiter::clear($this->throttleKey($user));
        $request->session()->forget([LoginController::PENDING_KEY, LoginController::PENDING_AT]);
        Auth::login($user);
        $request->session()->regenerate();

        $user->last_login_at = now();
        $user->save();
        Activity::log('login', [], null, $user);

        return redirect()->intended(route('sites.index'));
    }

    private function throttleKey(User $user): string
    {
        return '2fa:' . $user->id;
    }

    private function tooManyAttempts(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->throttleKey($user), 5);
    }
}
