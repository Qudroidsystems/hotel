<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Auth\TwoFactor;
use Illuminate\Http\Request;

/** Who uses two-step sign-in, and resetting it for someone who lost their phone. */
class TwoFactorAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Update user');
    }

    public function index(Request $request)
    {
        $filter = $request->query('show');
        $users = User::with('roles')->orderBy('name')->get()->map(fn ($u) => (object) [
            'user'     => $u,
            'enabled'  => TwoFactor::enabled($u),
            'required' => TwoFactor::required($u),
            'left'     => TwoFactor::enabled($u) ? TwoFactor::recoveryLeft($u) : null,
        ]);
        $counts = ['on' => $users->where('enabled', true)->count(), 'missing' => $users->where('required', true)->where('enabled', false)->count(), 'all' => $users->count()];
        $users = match ($filter) {
            'on'      => $users->where('enabled', true),
            'off'     => $users->where('enabled', false),
            'missing' => $users->where('required', true)->where('enabled', false),
            default   => $users,
        };
        $roles = config('two_factor.required_roles', []);
        $pagetitle = 'Two-step sign-in';

        return view('security.two-factor', compact('pagetitle', 'users', 'counts', 'roles', 'filter'));
    }

    public function reset(Request $request, User $user)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        abort_if($user->hasRole('Super Admin') && ! $request->user()->hasRole('Super Admin'), 403, 'Only a Super Admin can reset another Super Admin.');
        abort_unless(TwoFactor::enabled($user), 404);
        TwoFactor::disable($user);
        Audit::record('auth.2fa_reset', "Two-step sign-in reset for {$user->name}", $user, reason: $request->input('reason'), flagged: true);

        return back()->with('success', "Two-step sign-in reset for {$user->name}. " . (TwoFactor::required($user) ? 'They will set it up again at their next sign-in.' : 'They can sign in with their password alone.'));
    }
}
