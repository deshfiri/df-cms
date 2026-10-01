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

        // Phase 4 — public landing page. 'manage products' is also held by
        // the (unrelated) Product role, which runs internal product
        // sourcing/upload — not a fit for deciding what's shown publicly.
        // Toggling these specific fields needs Manager/Marketing, same as
        // the equivalent fields on Brand (see BrandController::update()).
        $publicFields = ['description', 'image', 'is_public'];
        if ($request->hasAny($publicFields)) {
            abort_unless($request->user()->hasAnyRole(['Manager', 'Marketing']), 403, 'Only Manager or Marketing can change a product\'s public visibility.');
        }

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:150', Rule::unique('products', 'name')->where(fn ($q) => $q->where('brand_id', $brand->id))->ignore($product->id)],
            'is_active'   => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'image'       => ['sometimes', 'nullable', 'url', 'max:2000'],
            'is_public'   => ['sometimes', 'boolean'],
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
