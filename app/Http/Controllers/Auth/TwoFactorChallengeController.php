<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Auth\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** The second step of signing in: the code from the authenticator app (or a recovery code). */
class TwoFactorChallengeController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest');
    }

    public function show(Request $request)
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login')->with('error', 'Please sign in again.');
        }

        return view('auth.two-factor.challenge');
    }

    public function store(Request $request)
    {
        $request->validate(['code' => 'required|string|max:20']);
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->with('error', 'That took too long. Please sign in again.');
        }

        $key = '2fa|' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Try again in ' . RateLimiter::availableIn($key) . ' seconds.']);
        }

        $how = TwoFactor::verify($user, $request->input('code'));
        if (! $how) {
            RateLimiter::hit($key, 60);
            $wrong = 5 - RateLimiter::remaining($key, 5);
            Audit::record('auth.2fa_failed', "Wrong sign-in code for {$user->name}", $user, by: $user, flagged: $wrong >= 5);
            throw ValidationException::withMessages(['code' => 'That code is not right. Check the time on your phone and try the newest code.']);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('two_factor.login')['remember'];
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        if ($how === 'recovery') {
            $left = TwoFactor::recoveryLeft($user);
            $request->session()->flash('warning', "You signed in with a recovery code. {$left} left. If your phone is lost, set up two-step sign-in again on a new one.");
        }
        if ($user->must_change_password) {
            return redirect()->to(route('profile.settings', ['id' => $user->id]) . '#security')
                ->with('error', 'Please set a new password before you continue.');
        }

        return redirect()->intended('/dashboard');
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('two_factor.login');
        if (! $pending || $pending['until'] < now()->getTimestamp()) {
            $request->session()->forget('two_factor.login');

            return null;
        }
        $user = User::find($pending['id']);

        return $user && ! $user->is_disabled && TwoFactor::enabled($user) ? $user : null;
    }
}
