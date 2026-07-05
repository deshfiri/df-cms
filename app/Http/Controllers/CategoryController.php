<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $service,
    ) {}

    public function index(Request $request)
    {
        if ($request->ajax() && $request->has('draw')) {
            return DataTables::of(Category::withCount('clients'))
                ->addColumn('status_badge', fn ($c) => $c->status ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>')
                ->addColumn('actions', fn ($c) => $request->user()->can('manage categories')
                    ? '<button class="btn btn-sm btn-warning btn-edit" data-id="' . $c->id . '" data-name="' . e($c->name) . '" data-status="' . $c->status . '"><i class="bi bi-pencil"></i></button> <button class="btn btn-sm btn-danger btn-delete" data-id="' . $c->id . '"><i class="bi bi-trash"></i></button>'
                    : '')
                ->rawColumns(['status_badge', 'actions'])
                ->make(true);
        }

        return view('settings.categories');
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->can('manage categories'), 403);

        $data = $request->validate(['name' => 'required|string|max:100|unique:categories,name']);
        $cat  = Category::create(['name' => $data['name'], 'slug' => Str::slug($data['name'])]);

        return response()->json(['success' => true, 'category' => $cat]);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        abort_unless(Auth::user()->can('manage categories'), 403);

        $data = $request->validate([
            'name'   => 'required|string|max:100|unique:categories,name,' . $category->id,
            'status' => 'boolean',
        ]);
        $updated = $this->service->update($category, $data);

        return response()->json(['success' => true, 'category' => $updated]);
    }

    public function destroy(Category $category): JsonResponse
    {
        abort_unless(Auth::user()->can('manage categories'), 403);

        if ($category->clients()->exists()) {
            return response()->json(['success' => false, 'message' => 'Category has clients. Reassign them first.'], 422);
        }
        $category->delete();

        return response()->json(['success' => true]);
    }
}
