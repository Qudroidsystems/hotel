<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /** Roles the system depends on; they can be edited but never deleted. */
    public const PROTECTED_ROLES = ['Super Admin'];

    public function __construct()
    {
        $this->middleware('permission:View role|Create role|Update role|Delete role|Add user-role|Update user-role|Remove user-role', ['only' => ['index', 'show', 'getRoleUsers']]);
        $this->middleware('permission:Create role', ['only' => ['store']]);
        $this->middleware('permission:Update role', ['only' => ['update']]);
        $this->middleware('permission:Delete role', ['only' => ['destroy']]);
        $this->middleware('permission:Update user-role|Add user-role', ['only' => ['adduser', 'updateuserrole']]);
        $this->middleware('permission:Remove user-role', ['only' => ['removeuserrole', 'bulkRemoveUsers']]);
    }

    public function index(): View
    {
        $pagetitle = 'Role Management';

        $roles = Role::with('permissions')->orderBy('name', 'DESC')->get();
        $role = $roles;
        $permission = Permission::get();
        $perm_title = $this->permissionGroups();

        return view('roles.index', compact('role', 'roles', 'permission'))
            ->with('perm_title', $perm_title)
            ->with('pagetitle', $pagetitle);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->validate($request, [
            'name'         => 'required|unique:roles,name',
            'permission'   => 'required|array',
            'permission.*' => 'exists:permissions,id',
            'badge'        => 'nullable|string',
        ]);

        $role = Role::create([
            'name'  => $request->input('name'),
            'badge' => $request->input('badge'),
        ]);

        $granted = Permission::whereIn('id', $request->input('permission'))->pluck('name')->toArray();
        $role->syncPermissions($granted);
        Audit::record('roles.permissions_changed', "Gave the new {$role->name} role " . count($granted) . ' permission(s)', $role, new: ['granted' => $granted]);

        return redirect()->route('roles.index')->with('success', 'Role created successfully');
    }

    public function show($id): View
    {
        $pagetitle = 'Role Management';

        $role = Role::findOrFail($id);
        $userRoleCount = DB::table('model_has_roles')->where('role_id', $id)->count();

        $usersWithRole = User::join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->where('model_has_roles.role_id', $id)
            ->where('model_has_roles.model_type', User::class)
            ->select([
                'users.id as id',
                'users.name as username',
                'users.email as email',
                'users.created_at as created_at',
                'model_has_roles.role_id as roleid',
            ])
            ->paginate(500);

        $rolePermissions = $role->permissions;
        $rolePermissions2 = $role->permissions->pluck('id', 'id')->all();
        $perm_title = $this->permissionGroups();

        Session::put('role_url', request()->fullUrl());

        return view('roles.show', compact(
            'role',
            'rolePermissions',
            'rolePermissions2',
            'usersWithRole',
            'userRoleCount',
            'pagetitle'
        ))->with('perm_title', $perm_title);
    }

    public function update(Request $request, $id): RedirectResponse
    {
        $role = Role::findOrFail($id);

        $this->validate($request, [
            'name'         => 'required|unique:roles,name,' . $id,
            'permission'   => 'required|array',
            'permission.*' => 'exists:permissions,id',
            'badge'        => 'nullable|string',
        ]);

        if (in_array($role->name, self::PROTECTED_ROLES, true) && $request->input('name') !== $role->name) {
            return back()->with('error', "The {$role->name} role cannot be renamed.");
        }

        $role->update([
            'name'  => $request->input('name'),
            'badge' => $request->input('badge'),
        ]);

        $before = $role->permissions->pluck('name')->all();
        $after = Permission::whereIn('id', $request->input('permission'))->pluck('name')->toArray();
        $role->syncPermissions($after);
        $this->auditPermissions($role, $before, $after);

        if (session('role_url')) {
            return redirect(session('role_url'))->with('success', 'Role updated successfully');
        }

        return redirect()->route('roles.index')->with('success', 'Role updated successfully');
    }

    public function adduser($id): View
    {
        $pagetitle = 'Role Management';

        $role = Role::findOrFail($id);
        $users = User::whereDoesntHave('roles', fn ($q) => $q->where('name', $role->name))
            ->orderBy('name')
            ->get();

        return view('roles.adduser', compact('role', 'users', 'pagetitle'));
    }

    public function updateuserrole(Request $request): RedirectResponse
    {
        $this->validate($request, [
            'users'   => 'required|array',
            'users.*' => 'exists:users,id',
            'roleid'  => 'required|exists:roles,id',
        ]);

        $role = Role::findOrFail($request->input('roleid'));

        User::whereIn('id', $request->input('users'))->get()
            ->each(function (User $user) use ($role) {
                if (! $user->hasRole($role->name)) {
                    $user->assignRole($role->name);
                    Audit::record('roles.user_added', "Gave {$user->name} the {$role->name} role", $user, new: ['role' => $role->name], flagged: $role->name === 'Super Admin');
                }
            });

        return redirect()->route('roles.show', $role->id)->with('success', 'Users added to role successfully');
    }

    public function removeuserrole($userid, $roleid): JsonResponse
    {
        try {
            $user = User::findOrFail($userid);
            $role = Role::findOrFail($roleid);

            if ($user->is(auth()->user()) && in_array($role->name, self::PROTECTED_ROLES, true)) {
                return response()->json(['success' => false, 'message' => "You cannot remove the {$role->name} role from yourself."], 422);
            }

            $user->removeRole($role->name);
            Audit::record('roles.user_removed', "Took the {$role->name} role from {$user->name}", $user, old: ['role' => $role->name]);

            return response()->json(['success' => true, 'message' => 'User role removed successfully']);
        } catch (\Throwable $e) {
            Log::error('Error removing user role', ['error' => $e->getMessage(), 'user_id' => $userid, 'role_id' => $roleid]);
            return response()->json(['success' => false, 'message' => 'Error removing user role.'], 500);
        }
    }

    public function destroy($id): RedirectResponse
    {
        $role = Role::findOrFail($id);

        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            return redirect()->route('roles.index')->with('error', "The {$role->name} role cannot be deleted.");
        }

        $role->delete();

        return redirect()->route('roles.index')->with('success', 'Role deleted successfully');
    }

    public function bulkRemoveUsers(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'role_id'          => 'required|exists:roles,id',
                'selected_users'   => 'required|array',
                'selected_users.*' => 'exists:users,id',
            ]);

            $role = Role::findOrFail($request->role_id);
            $removedNames = [];

            foreach (User::whereIn('id', $request->selected_users)->get() as $user) {
                if ($user->is(auth()->user()) && in_array($role->name, self::PROTECTED_ROLES, true)) {
                    continue;
                }
                if ($user->hasRole($role->name)) {
                    $user->removeRole($role);
                    Audit::record('roles.user_removed', "Took the {$role->name} role from {$user->name}", $user, old: ['role' => $role->name]);
                    $removedNames[] = $user->name;
                }
            }

            if ($removedNames) {
                $count = count($removedNames);
                return response()->json([
                    'success'       => true,
                    'message'       => "Successfully removed {$count} user(s) from the {$role->name} role.",
                    'removed_count' => $count,
                    'removed_users' => $removedNames,
                ]);
            }

            return response()->json(['success' => false, 'message' => 'No users were removed. They may not have had this role.']);
        } catch (\Throwable $e) {
            Log::error('Bulk remove users error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to remove users.'], 500);
        }
    }

    /** Records which permissions a role gained and lost. */
    private function auditPermissions(Role $role, array $before, array $after): void
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        if (! $added && ! $removed) {
            return;
        }
        Audit::record('roles.permissions_changed',
            "Changed the permissions of the {$role->name} role (" . count($added) . ' added, ' . count($removed) . ' removed)',
            $role, $removed ? ['removed' => $removed] : [], $added ? ['added' => $added] : []);
    }

    public function getRoleUsers(Role $role, Request $request)
    {
        $usersWithRole = $role->users()
            ->orderBy('name')
            ->paginate((int) $request->get('per_page', 10), ['*'], 'page', (int) $request->get('page', 1));

        $userRoleCount = $role->users()->count();

        if ($request->ajax()) {
            return response()->json([
                'success'      => true,
                'html'         => view('roles.partials.users_table_rows', ['users' => $usersWithRole, 'role' => $role])->render(),
                'pagination'   => view('roles.partials.pagination', ['users' => $usersWithRole])->render(),
                'total'        => $userRoleCount,
                'current_page' => $usersWithRole->currentPage(),
                'last_page'    => $usersWithRole->lastPage(),
                'per_page'     => $usersWithRole->perPage(),
            ]);
        }

        return redirect()->route('roles.show', $role->id);
    }

    /** Distinct permission group titles, used to group checkboxes in the role editor. */
    private function permissionGroups(): array
    {
        return Permission::query()->whereNotNull('title')->distinct()->orderBy('title')->pluck('title')->all();
    }
}
