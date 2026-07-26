<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BrandService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(Client $client, array $data): Brand
    {
        return DB::transaction(function () use ($client, $data) {
            $brand = Brand::create(array_merge($data, [
                'client_id'  => $client->id,
                'created_by' => Auth::id(),
            ]));

            $this->activityLog->log('Brand', 'Created', $client->id, null, ['name' => $brand->name]);

            return $brand;
        });
    }

    public function update(Brand $brand, array $data): Brand
    {
        return DB::transaction(function () use ($brand, $data) {
            $old = $brand->only(array_keys($data));

            $brand->update(array_merge($data, ['updated_by' => Auth::id()]));

            $this->activityLog->log('Brand', 'Updated', $brand->client_id, $old, $data);

            return $brand;
        });
    }

    public function delete(Brand $brand): void
    {
        DB::transaction(function () use ($brand) {
            $this->activityLog->log('Brand', 'Deleted', $brand->client_id, ['name' => $brand->name]);

            // Brand is soft-deleted, so the ad_campaigns.brand_id "nullOnDelete" FK action
            // never fires (no real SQL DELETE happens) — null it out explicitly here.
            $brand->adCampaigns()->update(['brand_id' => null]);
            $brand->delete();
        });
    }
}
