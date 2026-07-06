<?php

namespace App\Services;

use App\Models\Client;
use App\Repositories\Contracts\ClientRepositoryInterface;
use App\Repositories\Contracts\WorkflowRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ClientService
{
    public function __construct(
        private readonly ClientRepositoryInterface   $clientRepo,
        private readonly WorkflowRepositoryInterface $workflowRepo,
        private readonly ActivityLogService          $activityLog,
        private readonly ChangeApprovalService        $changeApproval,
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
        if (($data['client_status'] ?? null) === 'Terminated' && $client->client_status !== 'Terminated') {
            $this->guardTermination();
        }

        $this->changeApproval->guard(Client::class, $client->id, $client->only(array_keys($data)), $data, Auth::user());

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
        if ($status === 'Terminated' && $client->client_status !== 'Terminated') {
            $this->guardTermination();
        }

        $this->changeApproval->guard(
            Client::class,
            $client->id,
            ['client_status' => $client->client_status],
            ['client_status' => $status],
            Auth::user()
        );

        $old = $client->client_status;
        $updated = $this->clientRepo->update($client, [
            'client_status' => $status,
            'updated_by'    => Auth::id(),
        ]);
        $this->activityLog->log('Client', 'Status Changed', $client->id, $old, $status);

        return $updated;
    }

    /**
     * Setting a client to Terminated permanently locks its workflow, so it's
     * restricted to Super Admin/Manager regardless of which path (single
     * status change, bulk terminate, or the general edit form) is used.
     */
    private function guardTermination(): void
    {
        if (!Auth::user()->can('terminate', Client::class)) {
            throw new AuthorizationException('Only Super Admin or Manager can terminate a client.');
        }
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
