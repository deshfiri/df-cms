<?php

namespace App\Services;

use App\Models\Client;
use App\Repositories\Contracts\ClientRepositoryInterface;
use App\Repositories\Contracts\WorkflowRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ClientService
{
    public function __construct(
        private readonly ClientRepositoryInterface   $clientRepo,
        private readonly WorkflowRepositoryInterface $workflowRepo,
        private readonly ActivityLogService          $activityLog,
    ) {}

    public function create(array $data): Client
    {
        return DB::transaction(function () use ($data) {
            $data['dfid_number'] ??= $this->clientRepo->nextDfidNumber();
            $data['created_by']    = Auth::id();
            $data['updated_by']    = Auth::id();

            $client = $this->clientRepo->create($data);
            $this->workflowRepo->initClientStages($client->id);

            $this->activityLog->log('Client', 'Created', $client->id, null, $client->toArray());

            return $client;
        });
    }

    public function update(Client $client, array $data): Client
    {
        return DB::transaction(function () use ($client, $data) {
            $old = $client->toArray();
            $data['updated_by'] = Auth::id();

            $updated = $this->clientRepo->update($client, $data);
            $this->activityLog->log('Client', 'Updated', $client->id, $old, $updated->toArray());

            return $updated;
        });
    }

    public function delete(Client $client): void
    {
        DB::transaction(function () use ($client) {
            $this->activityLog->log('Client', 'Deleted', $client->id, $client->toArray());
            $this->clientRepo->delete($client);
        });
    }

    public function updateStatus(Client $client, string $status): Client
    {
        $old = $client->client_status;
        $updated = $this->clientRepo->update($client, [
            'client_status' => $status,
            'updated_by'    => Auth::id(),
        ]);
        $this->activityLog->log('Client', 'Status Changed', $client->id, $old, $status);

        return $updated;
    }

    public function getDashboardData(): array
    {
        return [
            'status_counts' => $this->clientRepo->statusCounts(),
            'recent'        => $this->clientRepo->recentlyJoined(8),
            'total'         => array_sum($this->clientRepo->statusCounts()),
        ];
    }
}
