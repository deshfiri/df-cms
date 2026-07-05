<?php

namespace App\Repositories;

use App\Models\ClientStageProgress;
use App\Models\WorkflowStage;
use App\Repositories\Contracts\WorkflowRepositoryInterface;
use Illuminate\Support\Collection;

class WorkflowRepository implements WorkflowRepositoryInterface
{
    public function allStages(): Collection
    {
        return WorkflowStage::orderBy('sort_order')->get();
    }

    public function activeStages(): Collection
    {
        return WorkflowStage::active()->get();
    }

    public function createStage(array $data): WorkflowStage
    {
        return WorkflowStage::create($data);
    }

    public function updateStage(WorkflowStage $stage, array $data): WorkflowStage
    {
        $stage->update($data);

        return $stage->fresh();
    }

    public function getClientProgress(int $clientId): Collection
    {
        return ClientStageProgress::with('stage')
            ->where('client_id', $clientId)
            ->get()
            ->keyBy('stage_id');
    }

    public function toggleStage(int $clientId, int $stageId, bool $completed, int $userId): ClientStageProgress
    {
        $progress = ClientStageProgress::firstOrCreate(
            ['client_id' => $clientId, 'stage_id' => $stageId]
        );

        $progress->update([
            'is_completed' => $completed,
            'completed_at' => $completed ? now() : null,
            'completed_by' => $completed ? $userId : null,
        ]);

        return $progress;
    }

    public function initClientStages(int $clientId): void
    {
        $stages = WorkflowStage::active()->pluck('id');
        foreach ($stages as $stageId) {
            ClientStageProgress::firstOrCreate(
                ['client_id' => $clientId, 'stage_id' => $stageId],
                ['is_completed' => false]
            );
        }
    }

    public function calculateProgress(int $clientId): int
    {
        $total = WorkflowStage::where('status', true)->count();
        if ($total === 0) {
            return 0;
        }
        $completed = ClientStageProgress::where('client_id', $clientId)
            ->where('is_completed', true)
            ->count();

        return (int) round(($completed / $total) * 100);
    }

    public function findStage(int $stageId): WorkflowStage
    {
        return WorkflowStage::findOrFail($stageId);
    }

    public function getOrCreateProgress(int $clientId, int $stageId): ClientStageProgress
    {
        return ClientStageProgress::firstOrCreate(
            ['client_id' => $clientId, 'stage_id' => $stageId],
            ['status' => ClientStageProgress::STATUS_PENDING]
        );
    }

    public function stagesBefore(WorkflowStage $stage): Collection
    {
        return WorkflowStage::where('status', true)
            ->where('sort_order', '<', $stage->sort_order)
            ->orderBy('sort_order')
            ->get();
    }

    public function nextActiveStage(WorkflowStage $stage): ?WorkflowStage
    {
        return WorkflowStage::where('status', true)
            ->where('sort_order', '>', $stage->sort_order)
            ->orderBy('sort_order')
            ->first();
    }
}
