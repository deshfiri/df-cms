<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Client;
use App\Services\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    public function __construct(
        private readonly BrandService $service,
    ) {}

    public function index(Client $client, Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['view ads', 'manage ads']), 403);

        return response()->json(['data' => $client->brands()->withCount('adCampaigns')->get()]);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('manage ads'), 403);

        $data = $request->validate([
            'name'    => ['required', 'string', 'max:150', Rule::unique('brands', 'name')->where(fn ($q) => $q->where('client_id', $client->id))],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $brand = $this->service->create($client, $data);

        return response()->json(['success' => true, 'data' => $brand]);
    }

    public function update(Request $request, Client $client, Brand $brand): JsonResponse
    {
        abort_if($brand->client_id !== $client->id, 404);
        abort_unless($request->user()->hasPermissionTo('manage ads'), 403);

        $data = $request->validate([
            'name'    => ['required', 'string', 'max:150', Rule::unique('brands', 'name')->where(fn ($q) => $q->where('client_id', $client->id))->ignore($brand->id)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->service->update($brand, $data);

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function destroy(Request $request, Client $client, Brand $brand): JsonResponse
    {
        abort_if($brand->client_id !== $client->id, 404);
        abort_unless($request->user()->hasPermissionTo('manage ads'), 403);

        $this->service->delete($brand);

        return response()->json(['success' => true]);
    }
}
