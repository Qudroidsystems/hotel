<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingException;
use App\Models\Booking\GuestSession;
use App\Services\Booking\GuestAccountService;
use Illuminate\Http\Request;

/**
 * Guest app sign-in and account (no staff account involved). The app sends
 * the session token in the X-Guest-Token header.
 */
class GuestAccountController extends ApiController
{
    public function __construct(private GuestAccountService $accounts)
    {
    }

    /** POST /guest/auth/code {contact} */
    public function code(Request $request)
    {
        $data = $request->validate(['contact' => 'required|string|max:150']);
        try {
            $via = $this->accounts->sendCode($data['contact'], $request->ip());
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }
        $where = str_contains($data['contact'], '@') ? 'your e-mail' : 'your phone';

        return $this->ok(['via' => $via === 'none' ? (str_contains($data['contact'], '@') ? 'email' : 'sms') : $via, 'minutes' => GuestAccountService::CODE_MINUTES],
            "If you’ve booked with us using that, a 6-digit code is on its way to {$where}.");
    }

    /** POST /guest/auth/verify {contact, code, device} */
    public function verify(Request $request)
    {
        $data = $request->validate(['contact' => 'required|string|max:150', 'code' => 'required|string|max:10', 'device' => 'nullable|string|max:120']);
        try {
            $out = $this->accounts->verify($data['contact'], $data['code'], $data['device'] ?? null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok(['token' => $out['token'], 'expires_at' => $out['session']->expires_at->toIso8601String(), 'account' => $this->accounts->profile($out['session'])], 'Signed in.');
    }

    /** GET /guest/me */
    public function me(Request $request)
    {
        return $this->ok($this->accounts->profile($this->session($request)));
    }

    /** PATCH /guest/me {first_name, last_name, nationality, marketing_opt_in} */
    public function update(Request $request)
    {
        $s = $this->session($request);
        $data = $request->validate([
            'first_name' => 'sometimes|required|string|max:100', 'last_name' => 'sometimes|required|string|max:100',
            'nationality' => 'sometimes|nullable|string|max:80', 'marketing_opt_in' => 'sometimes|boolean',
        ]);
        $this->accounts->update($s, $data);

        return $this->ok($this->accounts->profile($s), 'Saved.');
    }

    /** POST /guest/auth/logout */
    public function logout(Request $request)
    {
        $this->accounts->signOut($this->session($request));

        return $this->ok(null, 'Signed out.');
    }

    private function session(Request $request): GuestSession
    {
        $s = $this->accounts->session($request->header('X-Guest-Token'));
        abort_unless($s, 401, 'Please sign in again.');

        return $s;
    }
}
