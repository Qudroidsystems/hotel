<?php

namespace App\Http\Middleware;

use App\Support\Auth\TwoFactor;
use Closure;
use Illuminate\Http\Request;

/** People whose role requires two-step sign-in are sent to set it up before they can use anything else. */
class EnsureTwoFactorSetUp
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! TwoFactor::required($user) || TwoFactor::enabled($user)
            || $request->routeIs('two-factor.*', 'logout', 'login')) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Set up two-step sign-in first.'], 403);
        }

        return redirect()->route('two-factor.show')->with('warning', 'Your role needs two-step sign-in. Set it up with an authenticator app to continue.');
    }
}
