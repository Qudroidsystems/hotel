<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View permission|Create permission|Update permission|Delete permission', ['only' => ['index']]);
        $this->middleware('permission:Create permission', ['only' => ['store']]);
        $this->middleware('permission:Update permission', ['only' => ['update']]);
        $this->middleware('permission:Delete permission', ['only' => ['destroy']]);
    }

    public function index()
    {
        $pagetitle = 'Permissions';
        $data = Permission::with('roles')->orderBy('title')->orderBy('name')->get();

        return view('permissions.index', compact('data', 'pagetitle'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:255|unique:permissions,name',
            'title' => 'nullable|string|max:255',
        ]);

        Permission::create($data + ['guard_name' => 'web']);

        return redirect()->route('permissions.index')->with('success', 'Permission created successfully.');
    }

    public function update(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);

        $data = $request->validate([
            'name'  => 'required|string|max:255|unique:permissions,name,' . $permission->id,
            'title' => 'nullable|string|max:255',
        ]);

        $permission->update($data);

        return redirect()->route('permissions.index')->with('success', 'Permission updated successfully.');
    }

    public function destroy($id)
    {
        Permission::findOrFail($id)->delete();

        return redirect()->route('permissions.index')->with('success', 'Permission deleted successfully.');
    }
}
