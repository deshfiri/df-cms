<?php

namespace App\Repositories;

use App\Models\Client;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Repositories\Contracts\ClientRepositoryInterface;
use App\Services\ClientProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ClientRepository implements ClientRepositoryInterface
{
    public function query(): Builder
    {
        // flowItems drives the progress bar; eager-loaded (rather than counted
        // in SQL) because the percentage is stage-weighted, and the list is
        // paginated so only the visible page is hydrated.
        return Client::with([
            'category',
            'assignedUser',
            'flowItems' => fn ($q) => $q->with(ClientProgressService::EAGER_LOAD),
        ]);
    }

    public function findById(int $id): ?Client
    {
        return Client::with([
            'category', 'assignedUser', 'stageProgress.stage',
            'productUpdates.createdBy', 'payments.createdBy',
            'documents.uploadedBy', 'notes.user', 'activityLogs.user',
        ])->find($id);
    }

    public function findByDfid(string $dfid): ?Client
    {
        return Client::where('dfid_number', $dfid)->first();
    }

    public function create(array $data): Client
    {
        return Client::create($data);
    }

    public function update(Client $client, array $data): Client
    {
        $client->update($data);

        return $client->fresh();
    }

    public function delete(Client $client): void
    {
        $client->delete();
    }

    public function allForExport(array $filters = [], ?User $scopeToUser = null): Collection
    {
        $query = $this->applyFilters($this->query(), $filters);

        if ($scopeToUser && !$scopeToUser->hasRole('Super Admin')) {
            $userId = $scopeToUser->id;
            $query->where(function ($q) use ($userId) {
                $q->whereNull('assigned_to')->orWhere('assigned_to', $userId);
            });
        }

        return $query->get();
    }

    public function statusCounts(): array
    {
        $counts = Client::selectRaw('client_status, COUNT(*) as count')
            ->groupBy('client_status')
            ->pluck('count', 'client_status')
            ->toArray();

        return array_merge(
            array_fill_keys(Client::$statuses, 0),
            $counts
        );
    }

    public function recentlyJoined(int $limit = 5): Collection
    {
        return Client::with('category')->latest('joining_date')->limit($limit)->get();
    }

    public function nextDfidNumber(): string
    {
        $last  = Client::withTrashed()->max('dfid_number');
        $parts = preg_match('/(\d+)$/', $last ?? 'DFP25000', $m) ? (int) $m[1] : 25000;

        return 'DFP' . ($parts + 1);
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['status'])) {
            $query->where('client_status', $filters['status']);
        }
        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (!empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }
        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        return $query;
    }
}
