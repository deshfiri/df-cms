<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $service,
    ) {}

    public function index(Request $request)
    {
        // The action buttons were permission-checked but the list itself was
        // not, so any signed-in user could read every colleague's name, email
        // and roles straight off the DataTables feed.
        abort_unless($request->user()->can('manage users'), 403);

        if ($request->ajax() && $request->has('draw')) {
            // Newest first until a column is picked to sort by.
            return DataTables::of(User::with('roles')->when(!$request->filled('order'), fn ($q) => $q->latest('id')))
                ->addColumn('roles_badges', fn ($u) => $u->roles->map(fn ($r) => '<span class="badge bg-info">' . e($r->name) . '</span>')->implode(' '))
                ->addColumn('status_badge', fn ($u) => $u->is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>')
                ->addColumn('actions', fn ($u) => $request->user()->can('manage users')
                    ? '<button class="btn btn-sm btn-warning btn-edit" '
                        . 'data-id="' . $u->id . '" '
                        . 'data-name="' . e($u->name) . '" '
                        . 'data-email="' . e($u->email) . '" '
                        . 'data-active="' . ($u->is_active ? '1' : '0') . '" '
                        . 'data-roles="' . e($u->roles->pluck('name')->implode(',')) . '">'
                        . '<i class="bi bi-pencil"></i></button>'
                        // No delete button on your own row — destroy() refuses it anyway.
                        . ($u->id !== $request->user()->id
                            ? ' <button class="btn btn-sm btn-danger btn-delete" '
                                . 'data-id="' . $u->id . '" '
                                . 'data-name="' . e($u->name) . '">'
                                . '<i class="bi bi-trash"></i></button>'
                            : '')
                    : '')
                ->rawColumns(['roles_badges', 'status_badge', 'actions'])
                ->make(true);
        }

        $roles = Role::all();

        return view('settings.users', compact('roles'));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->can('manage users'), 403);

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'roles'    => 'required|array|min:1',
            'roles.*'  => 'string|exists:roles,name',
        ]);

        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'is_active' => true]);
        $user->syncRoles($data['roles']);

        return response()->json(['success' => true, 'user' => $user]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless(Auth::user()->can('manage users'), 403);

        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'password'  => 'nullable|string|min:8',
            'roles'     => 'required|array|min:1',
            'roles.*'   => 'string|exists:roles,name',
            'is_active' => 'boolean',
        ]);

        $this->service->update($user, $data);

        return response()->json(['success' => true]);
    }

    public function destroy(User $user): JsonResponse
    {
        abort_unless(Auth::user()->can('manage users'), 403);

        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        // Removing the last Super Admin would leave nobody able to manage the system.
        if ($user->hasRole('Super Admin') && User::role('Super Admin')->count() <= 1) {
            return response()->json(['message' => 'This is the only Super Admin and cannot be deleted.'], 422);
        }

        try {
            $this->service->delete($user);
        } catch (QueryException $e) {
            report($e);

            return response()->json(['message' => 'This user is still linked to other records and cannot be deleted. Set them to Inactive instead.'], 422);
        }

        return response()->json(['success' => true]);
    }
}
