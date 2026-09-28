<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Products under a brand — the shared checklist's content items may
 * optionally belong to one. Gated on `manage products`, wired up here for
 * the first time (the permission existed but nothing checked it before).
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $service,
    ) {}

    public function index(Brand $brand, Request $request): JsonResponse
    {
        abort_unless($request->user()->can('view clients') || $request->user()->can('manage products'), 403);

        return response()->json(['data' => $brand->products()->get()]);
    }

    public function store(Request $request, Brand $brand): JsonResponse
    {
        abort_unless($request->user()->can('manage products'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('products', 'name')->where(fn ($q) => $q->where('brand_id', $brand->id))],
        ]);

        $product = $this->service->create($brand, $data);

        return response()->json(['success' => true, 'data' => $product]);
    }

    public function update(Request $request, Brand $brand, Product $product): JsonResponse
    {
        abort_if($product->brand_id !== $brand->id, 404);
        abort_unless($request->user()->can('manage products'), 403);

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:150', Rule::unique('products', 'name')->where(fn ($q) => $q->where('brand_id', $brand->id))->ignore($product->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $updated = $this->service->update($product, $data);

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function destroy(Request $request, Brand $brand, Product $product): JsonResponse
    {
        abort_if($product->brand_id !== $brand->id, 404);
        abort_unless($request->user()->can('manage products'), 403);

        $this->service->delete($product);

        return response()->json(['success' => true]);
    }
}
