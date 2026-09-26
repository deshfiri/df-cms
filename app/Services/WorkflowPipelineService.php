<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\FlowItem;
use Illuminate\Support\Collection;

/**
 * There can be several workflows in flight at once; the admin/manager
 * dashboard can only show one pipeline, so it reads whichever Flow has been
 * marked as lead (Workflows management → the star button). Before this
 * existed, the dashboard's workflow widgets read the old, retired
 * WorkflowStage/ClientStageProgress pipeline, which nothing driving real
 * client work writes to any more.
 */
class WorkflowPipelineService
{
    /** The workflow chosen to represent the company's pipeline on the dashboard. */
    public function leadFlow(): ?Flow
    {
        return Flow::where('is_lead', true)->with(['stages' => fn ($q) => $q->orderBy('position')])->first();
    }

    /**
     * How many items sit at each stage of the lead workflow, how many have
     * stalled there, and what share of all its items have cleared that
     * stage. Empty until an admin marks a workflow as lead.
     *
     * @return array<int,array{label:string,active:int,delayed:int,progress:int}>
     */
    public function segments(): array
    {
        $flow = $this->leadFlow();
        if (!$flow || $flow->stages->isEmpty()) {
            return [];
        }

        [$items, $positionOf] = $this->itemsFor($flow);
        $total = $items->count();

        return $flow->stages->map(function ($stage) use ($items, $positionOf, $total) {
            $atStage = $items->where('current_stage_id', $stage->id)->where('status', FlowItem::STATUS_OPEN);
            $cleared = $items->filter(fn ($item) => $this->hasCleared($item, $stage->position, $positionOf))->count();

            return [
                'label' => $stage->name,
                'active' => $atStage->count(),
                'delayed' => $atStage->where('updated_at', '<', now()->subDays(7))->count(),
                'progress' => $total ? (int) round($cleared / $total * 100) : 0,
            ];
        })->all();
    }

    /**
     * How many of the lead workflow's items have cleared each stage — the
     * dashboard's "Workflow Stage Completion" chart.
     *
     * @return array{labels:array<int,string>,data:array<int,int>}
     */
    public function completionChart(): array
    {
        $flow = $this->leadFlow();
        if (!$flow || $flow->stages->isEmpty()) {
            return ['labels' => [], 'data' => []];
        }

        [$items, $positionOf] = $this->itemsFor($flow);

        return [
            'labels' => $flow->stages->pluck('name')->all(),
            'data' => $flow->stages->map(
                fn ($stage) => $items->filter(fn ($item) => $this->hasCleared($item, $stage->position, $positionOf))->count()
            )->all(),
        ];
    }

    /**
     * Non-cancelled items on the flow, with each stage's position pre-loaded
     * so callers can work out who has cleared a stage without re-querying
     * per item.
     *
     * @return array{0:Collection<int,FlowItem>,1:Collection<int,int>}
     */
    private function itemsFor(Flow $flow): array
    {
        $items = FlowItem::where('flow_id', $flow->id)
            ->where('status', '!=', FlowItem::STATUS_CANCELLED)
            ->get(['id', 'current_stage_id', 'status', 'updated_at']);

        return [$items, $flow->stages->pluck('position', 'id')];
    }

    /** Whether an item has moved past (or finished beyond) the given stage. */
    private function hasCleared(FlowItem $item, int $stagePosition, Collection $positionOf): bool
    {
        if ($item->status === FlowItem::STATUS_COMPLETED) {
            return true;
        }
        $currentPosition = $positionOf->get($item->current_stage_id);

        return $currentPosition !== null && $currentPosition > $stagePosition;
    }
}
