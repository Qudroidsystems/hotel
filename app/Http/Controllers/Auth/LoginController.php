<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\TwoFactor;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    use AuthenticatesUsers {
        login as passwordLogin;
    }

    protected $redirectTo = '/dashboard';

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    public function showLoginForm()
    {
        return view('auth.login');
    }

    /**
     * Accounts with two-step sign-in: a right password only opens the code step
     * (two-factor.challenge); they are not signed in until the code is checked.
     */
    public function login(Request $request)
    {
        $this->validateLogin($request);
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendLockoutResponse($request);
        }

        $provider = $this->guard()->getProvider();
        $user = $provider->retrieveByCredentials($this->credentials($request));
        if ($user && ! $user->is_disabled && TwoFactor::enabled($user) && $provider->validateCredentials($user, $this->credentials($request))) {
            $this->clearLoginAttempts($request);
            $request->session()->regenerate();
            $request->session()->put('two_factor.login', [
                'id'       => $user->id,
                'remember' => $request->boolean('remember'),
                'until'    => now()->addMinutes(config('two_factor.challenge_minutes', 5))->getTimestamp(),
            ]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->passwordLogin($request);
    }

    /** The login box accepts either an email address or a username. */
    protected function credentials(Request $request)
    {
        $login = trim((string) $request->input($this->username()));
        $field = str_contains($login, '@') ? 'email' : 'username';

        return [$field => $login, 'password' => $request->input('password')];
    }

    protected function authenticated(Request $request, $user)
    {
        if ($user->is_disabled) {
            $this->guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                $this->username() => 'This account has been disabled. Please contact the hotel administrator.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        if ($user->must_change_password) {
            return redirect()->to(route('profile.settings', ['id' => $user->id]) . '#security')
                ->with('error', 'Please set a new password before you continue.');
        }

        if ($intendedUrl = session('intended')) {
            session()->forget('intended');

            if ($this->isValidIntendedUrl($intendedUrl, $request)) {
                return redirect($intendedUrl);
            }
        }

        return redirect()->intended($this->redirectPath());
    }

    protected function isValidIntendedUrl($url, $request): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host === null || $host === $request->getHost();
    }

    public function username()
    {
        return 'email';
    }

    public function logout(Request $request)
    {
        $this->guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
