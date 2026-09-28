<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(Brand $brand, array $data): Product
    {
        return DB::transaction(function () use ($brand, $data) {
            $product = Product::create(array_merge($data, [
                'brand_id'   => $brand->id,
                'created_by' => Auth::id(),
            ]));

            $this->activityLog->log('Product', 'Created', $brand->client_id, null, ['name' => $product->name]);

            return $product;
        });
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $old = $product->only(array_keys($data));

            $product->update($data);

            $this->activityLog->log('Product', 'Updated', $product->brand->client_id, $old, $data);

            return $product;
        });
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $this->activityLog->log('Product', 'Deleted', $product->brand->client_id, ['name' => $product->name]);

            $product->delete();
        });
    }
}
