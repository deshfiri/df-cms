<?php

namespace App\Repositories\Contracts;

use App\Models\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

interface ClientRepositoryInterface
{
    public function query(): Builder;

    public function findById(int $id): ?Client;

    public function findByDfid(string $dfid): ?Client;

    public function create(array $data): Client;

    public function update(Client $client, array $data): Client;

    public function delete(Client $client): void;

    public function allForExport(array $filters = []): Collection;

    public function statusCounts(): array;

    public function recentlyJoined(int $limit = 5): Collection;

    public function nextDfidNumber(): string;
}
