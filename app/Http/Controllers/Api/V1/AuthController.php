<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Mobile\MobileUser;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/** Signing in and out of the staff mobile app. */
class AuthController extends ApiController
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'login'       => 'required|string|max:190',
            'password'    => 'required|string|max:200',
            'device_name' => 'nullable|string|max:80',
            'platform'    => 'nullable|in:ios,android',
            'push_token'  => 'nullable|string|max:255',
            'app_version' => 'nullable|string|max:20',
        ]);
        $login = trim($data['login']);
        $user = $this->findUser($login);

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            event(new Failed('sanctum', $user, [str_contains($login, '@') ? 'email' : 'username' => $login]));

            return $this->fail('That e-mail, username or password is not right.', 422, 'invalid_credentials');
        }
        if ($user->is_disabled) {
            return $this->fail('This account has been disabled. Please contact the hotel administrator.', 403, 'account_disabled');
        }

        $device = $data['device_name'] ?? 'Phone';
        $token = $user->createToken(mb_substr($device, 0, 80), ['staff-app'])->plainTextToken;
        if (! empty($data['push_token'])) {
            $this->remember($user, $data);
        }
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        Audit::record('auth.login', "{$user->name} signed in on the mobile app ({$device})", $user, by: $user);

        return $this->ok(['token' => $token, 'user' => MobileUser::toArray($user)], 'Signed in.');
    }

    public function me(Request $request)
    {
        return $this->ok(MobileUser::toArray($request->user()));
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $user->currentAccessToken()?->delete();
        Audit::record('auth.logout', "{$user->name} signed out of the mobile app", $user, by: $user);

        return $this->ok(null, 'Signed out.');
    }

    public function logoutAll(Request $request)
    {
        $user = $request->user();
        $user->tokens()->delete();
        MobileDevice::where('user_id', $user->id)->delete();
        Audit::record('auth.logout', "{$user->name} signed out of the mobile app on every phone", $user, by: $user);

        return $this->ok(null, 'Signed out on every phone.');
    }

    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:190']);
        // Same answer whether or not the address has an account, so it can't be used to find accounts.
        try {
            Password::sendResetLink(['email' => $data['email']]);
        } catch (\Throwable) {
            // mail not set up: still the same answer
        }

        return $this->ok(null, 'If that address belongs to an account, a reset link has been sent to it.');
    }

    public function password(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => [$user->must_change_password ? 'nullable' : 'required', 'string'],
            'password'         => ['required', 'confirmed', PasswordRule::min(8)],
        ]);
        if (! $user->must_change_password && ! Hash::check($data['current_password'], $user->password)) {
            return $this->fail('Your current password is not right.', 422, 'wrong_password', ['current_password' => ['Your current password is not right.']]);
        }
        if (Hash::check($data['password'], $user->password)) {
            return $this->fail('Choose a password you have not used here before.', 422, 'same_password', ['password' => ['Choose a new password.']]);
        }

        $user->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false])->save();
        // Other phones must sign in again with the new password.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
        Audit::record('auth.password_changed', "{$user->name} changed their password on the mobile app", $user, by: $user);

        return $this->ok(MobileUser::toArray($user->fresh()), 'Password changed.');
    }

    // ── Phones for push notifications ─────────────────────────────────

    public function device(Request $request)
    {
        $data = $request->validate([
            'push_token'  => 'required|string|max:255',
            'platform'    => 'nullable|in:ios,android',
            'device_name' => 'nullable|string|max:80',
            'app_version' => 'nullable|string|max:20',
        ]);
        $this->remember($request->user(), $data);

        return $this->ok(null);
    }

    public function forgetDevice(Request $request)
    {
        $data = $request->validate(['push_token' => 'required|string|max:255']);
        MobileDevice::where('user_id', $request->user()->id)->where('push_token', $data['push_token'])->delete();

        return $this->ok(null);
    }

    private function remember(User $user, array $data): void
    {
        // A token belongs to one phone: if someone else signed in on it before, it moves to this person.
        MobileDevice::updateOrCreate(['push_token' => $data['push_token']], [
            'user_id'     => $user->id,
            'platform'    => $data['platform'] ?? null,
            'device_name' => $data['device_name'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'last_seen_at' => now(),
        ]);
    }

    /** E-mail, username or phone number, as on the website (which takes e-mail or username). */
    private function findUser(string $login): ?User
    {
        if (str_contains($login, '@')) {
            return User::where('email', $login)->first();
        }
        if ($user = User::where('username', $login)->first()) {
            return $user;
        }
        $digits = preg_replace('/\D+/', '', $login);
        if (strlen($digits) >= 9) {
            $matches = User::whereNotNull('phone_number')->where('phone_number', 'like', '%' . substr($digits, -9))->limit(2)->get();

            return $matches->count() === 1 ? $matches->first() : null;   // never guess between two people
        }

        return null;
    }
}
