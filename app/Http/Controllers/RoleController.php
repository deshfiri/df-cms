<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(auth()->user()->hasRole('Super Admin'), 403, 'Super Admin only.');
            return $next($request);
        });
    }

    public function index()
    {
        $roles       = Role::with('permissions')->withCount('users')->orderBy('name')->get();
        $permissions = Permission::orderBy('name')->get()->groupBy(fn ($p) => explode(':', $p->name)[0] ?? 'General');

        return view('roles.index', compact('roles', 'permissions'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100|unique:roles,name']);
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);

        return response()->json(['success' => true, 'role' => $role->load('permissions')]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        abort_if(in_array($role->name, ['Super Admin']), 403, 'Cannot rename Super Admin.');
        $data = $request->validate(['name' => 'required|string|max:100|unique:roles,name,' . $role->id]);
        $role->update(['name' => $data['name']]);

        return response()->json(['success' => true]);
    }

    public function destroy(Role $role): JsonResponse
    {
        abort_if($role->name === 'Super Admin', 403, 'Cannot delete Super Admin role.');
        abort_if($role->users()->count() > 0, 422, 'Cannot delete a role that has users assigned.');

        $role->delete();

        return response()->json(['success' => true]);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $ids = $request->validate(['permissions' => 'nullable|array', 'permissions.*' => 'integer|exists:permissions,id'])['permissions'] ?? [];
        $permissions = Permission::whereIn('id', $ids)->pluck('name');
        $role->syncPermissions($permissions->all());

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    public function clone(Request $request, Role $role): JsonResponse
    {
        $data  = $request->validate(['name' => 'required|string|max:100|unique:roles,name']);
        $clone = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $clone->syncPermissions($role->permissions->pluck('name')->all());

        return response()->json(['success' => true, 'role' => $clone->load('permissions')]);
    }
}
