<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Two-step sign-in rules: who must use it, checking a code, recovery codes. */
class TwoFactor
{
    public static function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && filled($user->two_factor_secret);
    }

    /** Their role means they may not work without it. */
    public static function required(User $user): bool
    {
        $roles = config('two_factor.required_roles', []);

        return $roles && $user->hasAnyRole($roles);
    }

    /**
     * Checks a six-digit app code or a recovery code. Returns 'app' or 'recovery', or null.
     * An app code is accepted once only; a recovery code is crossed off when used.
     */
    public static function verify(User $user, ?string $code): ?string
    {
        $code = trim((string) $code);
        if ($code === '' || ! filled($user->two_factor_secret)) {
            return null;
        }

        if (preg_match('/^\d[\d ]{4,8}\d$/', $code)) {
            $step = Totp::match($user->two_factor_secret, $code);
            if ($step === null || ($user->two_factor_last_step !== null && $step <= (int) $user->two_factor_last_step)) {
                return null;
            }
            $user->forceFill(['two_factor_last_step' => $step])->saveQuietly();

            return 'app';
        }

        $codes = $user->two_factor_recovery_codes ?? [];
        $typed = strtoupper(str_replace(' ', '', $code));
        foreach ($codes as $i => $hash) {
            if (Hash::check($typed, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->saveQuietly();
                Audit::record('auth.2fa_recovery_used', "{$user->name} used a recovery code to sign in (" . count($codes) . ' left)', $user, by: $user, flagged: count($codes) <= 2);

                return 'recovery';
            }
        }

        return null;
    }

    /** New recovery codes: shown once as plain text, stored hashed. */
    public static function newRecoveryCodes(User $user): array
    {
        $plain = collect(range(1, config('two_factor.recovery_codes', 8)))
            ->map(fn () => strtoupper(Str::random(5) . '-' . Str::random(5)))->all();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $plain)])->saveQuietly();

        return $plain;
    }

    public static function recoveryLeft(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /** Turn it off (by the person, an administrator, or the reset command). */
    public static function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
            'two_factor_last_step'      => null,
        ])->saveQuietly();
    }
}
