<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Auth\Totp;
use App\Support\Auth\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Setting up, checking and turning off two-step sign-in for your own account. */
class TwoFactorController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $enabled = TwoFactor::enabled($user);
        $required = TwoFactor::required($user);
        $secret = null;
        $uri = null;
        if (! $enabled) {
            // A new key for each set-up attempt is kept in the session until a code proves the app has it.
            $secret = $request->session()->get('two_factor.setup') ?? tap(Totp::secret(), fn ($s) => $request->session()->put('two_factor.setup', $s));
            $uri = Totp::uri($secret, $user->email, config('hotel.name', config('app.name')));
        }
        $codes = $request->session()->get('two_factor.codes');
        $left = $enabled ? TwoFactor::recoveryLeft($user) : 0;
        $pagetitle = 'Two-step sign-in';

        return view('auth.two-factor.setup', compact('pagetitle', 'user', 'enabled', 'required', 'secret', 'uri', 'codes', 'left'));
    }

    public function confirm(Request $request)
    {
        $request->validate(['code' => 'required|string|max:12']);
        $user = $request->user();
        $secret = $request->session()->get('two_factor.setup');
        if (TwoFactor::enabled($user) || ! $secret) {
            return redirect()->route('two-factor.show');
        }
        $step = Totp::match($secret, $request->input('code'));
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Scan the QR code again, then type the newest code from the app.']);
        }

        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->saveQuietly();
        $codes = TwoFactor::newRecoveryCodes($user);
        $request->session()->forget('two_factor.setup');
        Audit::record('auth.2fa_enabled', "{$user->name} turned on two-step sign-in", $user, by: $user);

        return redirect()->route('two-factor.show')->with('two_factor.codes', $codes)
            ->with('success', 'Two-step sign-in is on. Save your recovery codes now: they are shown only once.');
    }

    public function recoveryCodes(Request $request)
    {
        $user = $request->user();
        $this->checkPassword($request);
        abort_unless(TwoFactor::enabled($user), 404);
        $codes = TwoFactor::newRecoveryCodes($user);
        Audit::record('auth.2fa_codes_renewed', "{$user->name} made new recovery codes", $user, by: $user);

        return redirect()->route('two-factor.show')->with('two_factor.codes', $codes)->with('success', 'New recovery codes made. The old ones no longer work.');
    }

    public function disable(Request $request)
    {
        $user = $request->user();
        $this->checkPassword($request);
        if (TwoFactor::required($user)) {
            return back()->with('error', 'Your role must use two-step sign-in, so it can’t be turned off. To move it to a new phone, use “Move to a new phone”.');
        }
        TwoFactor::disable($user);
        Audit::record('auth.2fa_disabled', "{$user->name} turned off two-step sign-in", $user, by: $user, flagged: true);

        return redirect()->route('two-factor.show')->with('success', 'Two-step sign-in is off.');
    }

    /** Start again with a new key, e.g. on a new phone. The old phone stops working straight away. */
    public function restart(Request $request)
    {
        $user = $request->user();
        $this->checkPassword($request);
        TwoFactor::disable($user);
        $request->session()->forget('two_factor.setup');
        Audit::record('auth.2fa_reset', "{$user->name} started setting up two-step sign-in on a new phone", $user, by: $user);

        return redirect()->route('two-factor.show')->with('success', 'Scan the new QR code with the app on your new phone.');
    }

    private function checkPassword(Request $request): void
    {
        $request->validate(['password' => 'required|string']);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not right.']);
        }
    }
}
