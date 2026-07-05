<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
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
        $permissions = Permission::orderBy('name')
            ->get()
            ->groupBy(fn ($p) => explode(':', $p->name)[0] ?? 'General');

        return view('permissions.index', compact('permissions'));
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
