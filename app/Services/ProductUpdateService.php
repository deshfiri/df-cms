<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ProductUpdate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductUpdateService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(Client $client, array $data): ProductUpdate
    {
        return DB::transaction(function () use ($client, $data) {
            $data['client_id']  = $client->id;
            $data['created_by'] = Auth::id();

            $update = ProductUpdate::create($data);
            $this->activityLog->log('Product', 'Update Created', $client->id, null, $data);

            return $update;
        });
    }

    public function delete(ProductUpdate $update): void
    {
        DB::transaction(function () use ($update) {
            $this->activityLog->log('Product', 'Update Deleted', $update->client_id, $update->toArray());
            $update->delete();
        });
    }
}
