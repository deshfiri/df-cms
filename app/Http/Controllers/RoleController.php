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

    /**
     * Permission name => the group it is listed under, matched on the first
     * keyword that appears in the name. Order matters: the first match wins.
     *
     * Anything unmatched falls into "Other", so a new permission always shows
     * up on this page rather than disappearing.
     */
    private const GROUPS = [
        'Clients'      => ['client'],
        'Payments'     => ['payment', 'refund', 'invoice'],
        'Tasks'        => ['task'],
        'Workflow'     => ['workflow', 'stage'],
        'Requests'     => ['request'],
        'Documents'    => ['document', 'file-manager', 'product'],
        'Marketing'    => ['ads', 'campaign'],
        'WhatsApp'     => ['whatsapp'],
        'Chat'         => ['chat'],
        'Performance'  => ['performance', 'review', 'report'],
        'Meetings'     => ['meeting'],
        'Data'         => ['import', 'export'],
        'Settings'     => ['setting', 'user', 'categor', 'sound'],
    ];

    public function index()
    {
        $roles = Role::with('permissions')->withCount('users')->orderBy('name')->get();

        // Grouped by what they govern. This used to split on a ":" that no
        // permission name contains, so every single one became its own heading
        // and the list was impossible to read.
        $permissions = Permission::orderBy('name')->get()
            ->groupBy(fn (Permission $p) => self::groupFor($p->name))
            ->sortKeys();

        return view('roles.index', [
            'roles'           => $roles,
            'permissions'     => $permissions,
            'permissionTotal' => $permissions->flatten()->count(),
        ]);
    }

    private static function groupFor(string $name): string
    {
        foreach (self::GROUPS as $group => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    return $group;
                }
            }
        }

        return 'Other';
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
