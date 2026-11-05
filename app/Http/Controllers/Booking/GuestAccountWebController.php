<?php

namespace App\Http\Controllers\Booking;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Services\Booking\GuestAccountService;
use App\Services\Booking\SocialSignIn;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The guest's account on the website (/account): the same account as the
 * guest app. Sign in with a code by e-mail, or with Google / Apple, then see
 * every booking made with that e-mail. The guest session token is kept in the
 * browser session.
 */
class GuestAccountWebController extends Controller
{
    public function __construct(private GuestAccountService $accounts, private SocialSignIn $social)
    {
    }

    public function show(Request $request)
    {
        $session = $this->accounts->session($request->session()->get('guest_account.token'));
        if ($session) {
            return view('booking.account', ['account' => $this->accounts->profile($session)]);
        }
        $request->session()->forget('guest_account.token');
        if (! $request->session()->has('guest_account.nonce')) {
            $request->session()->put('guest_account.nonce', Str::random(32));
        }

        return view('booking.account-sign-in', [
            'providers' => $this->social->publicConfig(),
            'nonce' => $request->session()->get('guest_account.nonce'),
            'contact' => $request->session()->get('guest_account.contact'),
            'step' => $request->query('step') === 'code' && $request->session()->has('guest_account.contact') ? 'code' : 'contact',
        ]);
    }

    /** POST /account/code {contact} */
    public function code(Request $request)
    {
        $data = $request->validate(['contact' => 'required|string|max:150']);
        try {
            $this->accounts->sendCode($data['contact'], $request->ip());
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $request->session()->put('guest_account.contact', trim($data['contact']));
        $where = str_contains($data['contact'], '@') ? 'your e-mail' : 'your phone';
        $redirect = redirect()->route('account.show', ['step' => 'code'])->with('success', "If you’ve booked with us using that, a 6-digit code is on its way to {$where}.");

        return $this->accounts->devCode ? $redirect->with('dev_code', $this->accounts->devCode) : $redirect;
    }

    /** POST /account/verify {code} */
    public function verify(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:10']);
        $contact = (string) $request->session()->get('guest_account.contact');
        try {
            $out = $this->accounts->verify($contact, $data['code'], 'Website');
        } catch (BookingException $e) {
            return redirect()->route('account.show', ['step' => 'code'])->with('error', $e->getMessage());
        }

        return $this->signedIn($request, $out['token']);
    }

    /** POST /account/social {provider, id_token, first_name?, last_name?} — from the Google / Apple buttons. */
    public function social(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|in:google,apple', 'id_token' => 'required|string|max:5000',
            'first_name' => 'nullable|string|max:100', 'last_name' => 'nullable|string|max:100',
        ]);
        try {
            $out = $this->accounts->socialSignIn($data['provider'], $data['id_token'], 'Website',
                ['first_name' => $data['first_name'] ?? null, 'last_name' => $data['last_name'] ?? null], $request->session()->get('guest_account.nonce'));
        } catch (BookingException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()], 422) : redirect()->route('account.show')->with('error', $e->getMessage());
        }
        $this->signedIn($request, $out['token']);

        return $request->expectsJson() ? response()->json(['redirect' => route('account.show')]) : redirect()->route('account.show');
    }

    /**
     * POST /account/apple/callback — Apple posts here (form_post). The Android
     * app starts Apple sign-in in a browser tab, so its answer is handed back
     * to the app; a browser that couldn't use the pop-up signs in here.
     */
    public function appleCallback(Request $request)
    {
        $package = config('hotel.guest_app.android_package');
        if ($package && str_starts_with((string) $request->input('state'), 'android')) {
            $query = http_build_query($request->only(['code', 'id_token', 'state', 'user', 'error']));

            return redirect()->away("intent://callback?{$query}#Intent;package={$package};scheme=signinwithapple;end");
        }
        if (! $request->filled('id_token')) {
            return redirect()->route('account.show')->with('error', 'Apple sign-in was cancelled.');
        }
        $user = json_decode((string) $request->input('user'), true);
        try {
            // Apple posts across sites, so the browser session (and its nonce) may not come along: the token's signature and audience still have to match.
            $out = $this->accounts->socialSignIn('apple', (string) $request->input('id_token'), 'Website',
                ['first_name' => $user['name']['firstName'] ?? null, 'last_name' => $user['name']['lastName'] ?? null]);
        } catch (BookingException $e) {
            return redirect()->route('account.show')->with('error', $e->getMessage());
        }

        return $this->signedIn($request, $out['token']);
    }

    public function logout(Request $request)
    {
        if ($s = $this->accounts->session($request->session()->get('guest_account.token'))) {
            $this->accounts->signOut($s);
        }
        $request->session()->forget(['guest_account.token', 'guest_account.contact']);

        return redirect()->route('account.show')->with('success', 'Signed out.');
    }

    private function signedIn(Request $request, string $token)
    {
        $request->session()->regenerate();
        $request->session()->put('guest_account.token', $token);
        $request->session()->forget(['guest_account.contact', 'guest_account.nonce']);

        return redirect()->route('account.show');
    }
}
