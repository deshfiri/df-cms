<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductUpdate\StoreProductUpdateRequest;
use App\Models\Client;
use App\Models\ProductUpdate;
use App\Services\ProductUpdateService;
use Illuminate\Http\JsonResponse;

class ProductUpdateController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $service,
    ) {}

    public function index(Client $client): JsonResponse
    {
        $updates = $client->productUpdates()->with('createdBy:id,name')->get();

        return response()->json($updates);
    }

    public function store(StoreProductUpdateRequest $request, Client $client): JsonResponse
    {
        $update = $this->service->create($client, $request->validated());

        return response()->json([
            'success' => true,
            'update'  => $update->load('createdBy:id,name'),
        ]);
    }

    public function destroy(Client $client, ProductUpdate $productUpdate): JsonResponse
    {
        $this->service->delete($productUpdate);

        return response()->json(['success' => true]);
    }

    public function statuses(): JsonResponse
    {
        return response()->json(ProductUpdate::$statuses);
    }
}
