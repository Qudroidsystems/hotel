<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View user|Create user|Update user|Delete user', ['only' => ['index']]);
        $this->middleware('permission:Create user', ['only' => ['store']]);
        $this->middleware('permission:Update user', ['only' => ['update']]);
        $this->middleware('permission:Delete user', ['only' => ['destroy']]);
    }

    public function index(): View
    {
        $pagetitle = 'User Management';
        $data = User::with('roles')->latest()->get();
        $roles = Role::pluck('name', 'name')->toArray();

        $role_counts = [];
        foreach ($roles as $role) {
            $role_counts[$role] = User::role($role)->count();
        }
        $role_counts['No Role'] = User::doesntHave('roles')->count();

        return view('users.index', compact('data', 'roles', 'pagetitle', 'role_counts'));
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name'         => 'required|string|max:255',
                'email'        => 'required|email|unique:users,email',
                'password'     => 'required|min:8|confirmed',
                'roles'        => 'required|array',
                'roles.*'      => 'exists:roles,name',
                'phone_number' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            ]);

            $user = User::create([
                'name'         => $validated['name'],
                'email'        => $validated['email'],
                'phone_number' => $validated['phone_number'] ?? null,
                'password'     => Hash::make($validated['password']),
            ]);
            $user->syncRoles($validated['roles']);
            Audit::record('roles.user_added', "Gave {$user->name} the role(s) " . implode(', ', $validated['roles']), $user,
                new: ['roles' => $validated['roles']], flagged: in_array('Super Admin', $validated['roles'], true));

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'user'    => $this->present($user),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error("Create user error: {$e->getMessage()}");
            return response()->json(['success' => false, 'message' => 'Failed to create user'], 500);
        }
    }

    public function show($id): View
    {
        $pagetitle = 'User Overview';
        $user = User::with('roles')->findOrFail($id);

        // Everyone can see their own profile; other people's need "View user".
        abort_unless($user->is(auth()->user()) || auth()->user()->can('View user'), 403);

        return view('users.overview', compact('user', 'pagetitle'));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            $validated = $request->validate([
                'name'         => 'required|string|max:255',
                'email'        => 'required|email|unique:users,email,' . $user->id,
                'password'     => 'nullable|min:8|confirmed',
                'roles'        => 'required|array',
                'roles.*'      => 'exists:roles,name',
                'phone_number' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            ]);

            // Nobody may strip their own "Super Admin" role — it would lock the
            // hotel out of user management if they are the only one.
            if ($user->is(auth()->user()) && $user->hasRole('Super Admin')
                && ! in_array('Super Admin', $validated['roles'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot remove the Super Admin role from your own account.',
                ], 422);
            }

            $user->fill([
                'name'         => $validated['name'],
                'email'        => $validated['email'],
                'phone_number' => $validated['phone_number'] ?? null,
            ]);
            if (! empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }
            $user->save();
            $before = $user->getRoleNames()->all();
            $user->syncRoles($validated['roles']);
            if (array_diff($before, $validated['roles']) || array_diff($validated['roles'], $before)) {
                Audit::record('roles.user_roles_changed', "Changed the roles of {$user->name}", $user,
                    ['roles' => $before], ['roles' => array_values($validated['roles'])],
                    flagged: in_array('Super Admin', array_diff($validated['roles'], $before), true));
            }

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'user'    => $this->present($user),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error("Update user error: {$e->getMessage()}");
            return response()->json(['success' => false, 'message' => 'Failed to update user'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            if ($user->is(auth()->user())) {
                return response()->json(['success' => false, 'message' => 'You cannot delete your own account.'], 422);
            }

            if ($user->avatar) {
                Storage::disk('public')->delete(User::AVATAR_DIR . '/' . basename($user->avatar));
            }
            $user->roles()->detach();
            $user->delete();

            return response()->json(['success' => true, 'message' => 'User deleted successfully']);
        } catch (\Throwable $e) {
            Log::error("Delete user error: {$e->getMessage()}");
            return response()->json(['success' => false, 'message' => 'Failed to delete user'], 500);
        }
    }

    /** JSON list of role names (used by role pickers). */
    public function roles(): JsonResponse
    {
        return response()->json(['roles' => Role::pluck('name')->all()]);
    }

    private function present(User $user): array
    {
        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'phone_number' => $user->phone_number,
            'roles'        => $user->roles()->pluck('name')->toArray(),
        ];
    }
}
