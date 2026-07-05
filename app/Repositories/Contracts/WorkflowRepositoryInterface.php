<?php

namespace App\Repositories\Contracts;

use App\Models\ClientStageProgress;
use App\Models\WorkflowStage;
use Illuminate\Support\Collection;

interface WorkflowRepositoryInterface
{
    public function allStages(): Collection;

    public function activeStages(): Collection;

    public function createStage(array $data): WorkflowStage;

    public function updateStage(WorkflowStage $stage, array $data): WorkflowStage;

    public function getClientProgress(int $clientId): Collection;

    public function toggleStage(int $clientId, int $stageId, bool $completed, int $userId): ClientStageProgress;

    public function initClientStages(int $clientId): void;

    public function calculateProgress(int $clientId): int;

    public function findStage(int $stageId): WorkflowStage;

    public function getOrCreateProgress(int $clientId, int $stageId): ClientStageProgress;

    public function stagesBefore(WorkflowStage $stage): Collection;

    public function nextActiveStage(WorkflowStage $stage): ?WorkflowStage;
}
