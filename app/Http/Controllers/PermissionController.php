<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(auth()->user()->hasRole('Super Admin'), 403, 'Super Admin only.');
            return $next($request);
        });
    }

    public function index()
    {
        $permissions = Permission::orderBy('name')
            ->get()
            ->groupBy(fn ($p) => explode(':', $p->name)[0] ?? 'General');

        $users = User::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        // Shaped here rather than in the view: Blade cannot parse a multi-line
        // array literal as a directive argument, so @json() needs a variable.
        $permissionGroups = $permissions->map(fn ($perms, $category) => [
            'category' => $category,
            'items'    => $perms->map(fn ($p) => [
                'name'  => $p->name,
                'label' => explode(':', $p->name)[1] ?? $p->name,
            ])->values(),
        ])->values();

        return view('permissions.index', compact('permissions', 'users', 'permissionGroups'));
    }

    /**
     * What one person can do, and where each grant comes from.
     *
     * Roles remain the normal way to hand out access; this exists for the
     * exceptions — one person who needs one extra thing without inventing a
     * role for them.
     */
    public function showUser(User $user): JsonResponse
    {
        return response()->json([
            'user'           => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'roles'          => $user->getRoleNames()->values(),
            // A Super Admin passes every check through Gate::before, so editing
            // their grants changes nothing — the UI says so rather than
            // pretending the checkboxes matter.
            'is_super_admin' => $user->hasRole('Super Admin'),
            'direct'         => $user->getDirectPermissions()->pluck('name')->values(),
            'via_roles'      => $user->getPermissionsViaRoles()->pluck('name')->unique()->values(),
        ]);
    }

    /**
     * Replace this user's *direct* permissions.
     *
     * syncPermissions only touches direct grants — anything the user gets from
     * a role is left alone and cannot be taken away here. Removing access that
     * came from a role means changing the role, or the user's roles.
     */
    public function syncUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $before = $user->getDirectPermissions()->pluck('name')->sort()->values()->all();
        $after  = collect($data['permissions'])->unique()->sort()->values()->all();

        $user->syncPermissions($after);

        if ($before !== $after) {
            // Security-relevant and not otherwise recoverable — who granted what,
            // to whom, and when.
            $this->activityLog->log(
                module: 'Permissions',
                action: 'Direct permissions changed for ' . $user->name,
                clientId: null,
                oldValue: $before,
                newValue: $after,
            );
        }

        return response()->json([
            'success' => true,
            'direct'  => $user->fresh()->getDirectPermissions()->pluck('name')->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:150|unique:permissions,name',
            'category' => 'nullable|string|max:80',
        ]);

        $fullName   = $data['category'] ? "{$data['category']}:{$data['name']}" : $data['name'];
        $permission = Permission::create(['name' => $fullName, 'guard_name' => 'web']);

        return response()->json(['success' => true, 'permission' => $permission]);
    }

    public function destroy(Permission $permission): JsonResponse
    {
        $permission->delete();

        return response()->json(['success' => true]);
    }
}
