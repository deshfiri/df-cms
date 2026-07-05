<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;

class UserController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax() && $request->has('draw')) {
            return DataTables::of(User::with('roles'))
                ->addColumn('roles_badges', fn ($u) => $u->roles->map(fn ($r) => '<span class="badge bg-info">' . e($r->name) . '</span>')->implode(' '))
                ->addColumn('status_badge', fn ($u) => $u->is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>')
                ->addColumn('actions', fn ($u) => '<button class="btn btn-sm btn-warning btn-edit" '
                    . 'data-id="' . $u->id . '" '
                    . 'data-name="' . e($u->name) . '" '
                    . 'data-email="' . e($u->email) . '" '
                    . 'data-active="' . ($u->is_active ? '1' : '0') . '" '
                    . 'data-roles="' . e($u->roles->pluck('name')->implode(',')) . '">'
                    . '<i class="bi bi-pencil"></i></button>')
                ->rawColumns(['roles_badges', 'status_badge', 'actions'])
                ->make(true);
        }

        $roles = Role::all();

        return view('settings.users', compact('roles'));
    }

    public function store(Request $request): JsonResponse
    {
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
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'password'  => 'nullable|string|min:8',
            'roles'     => 'required|array|min:1',
            'roles.*'   => 'string|exists:roles,name',
            'is_active' => 'boolean',
        ]);

        $updateData = ['name' => $data['name'], 'email' => $data['email'], 'is_active' => $data['is_active'] ?? $user->is_active];
        if (!empty($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        $user->update($updateData);
        $user->syncRoles($data['roles']);

        return response()->json(['success' => true]);
    }
}
