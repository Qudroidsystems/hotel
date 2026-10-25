<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every signed-in call from the staff mobile app: a disabled account is signed
 * out at once, and a temporary password must be changed before anything else.
 */
class MobileStaff
{
    /** Calls allowed while a temporary password is still in use. */
    private const BEFORE_NEW_PASSWORD = ['api/v1/auth/me', 'api/v1/auth/password', 'api/v1/auth/logout', 'api/v1/auth/logout-all', 'api/v1/devices'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->is_disabled) {
            $user->tokens()->delete();

            return response()->json(['success' => false, 'code' => 'account_disabled',
                'message' => 'This account has been disabled. Please contact the hotel administrator.'], 401);
        }

        if ($user?->must_change_password && ! in_array($request->path(), self::BEFORE_NEW_PASSWORD, true)) {
            return response()->json(['success' => false, 'code' => 'password_change_required',
                'message' => 'Set a new password before you continue.'], 403);
        }

        return $next($request);
    }
}
