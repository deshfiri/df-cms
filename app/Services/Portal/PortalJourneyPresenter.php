<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\FlowItem;
use App\Models\FlowStage;
use App\Services\ClientProgressService;
use Illuminate\Support\Collection;

/**
 * Client-safe view of the work running for a client.
 *
 * Reads the flow engine, which replaced the WorkflowStage pipeline. The old
 * pipeline is no longer written to, so this used to render the same nineteen
 * seeded stages as permanently "Pending" for every client.
 *
 * Only flows marked client_visible are presented, and only fields a client
 * should see: internal notes, assignees, and who moved what never leave here.
 * The output shape is unchanged from the pipeline version, so the portal views
 * and dashboard consume it exactly as before.
 */
class PortalJourneyPresenter
{
    public function __construct(
        private readonly ClientProgressService $progress,
    ) {}

    /**
     * Every stage of every client-visible flow item, flattened into one
     * timeline in the order the work runs.
     */
    public function present(Client $client): array
    {
        $entries = [];

        foreach ($this->items($client) as $item) {
            foreach ($item->flow?->stages ?? [] as $stage) {
                $entries[] = $this->entry($item, $stage);
            }
        }

        return $entries;
    }

    /**
     * The same percentage staff see on the client list — one definition for
     * both sides, so the client is never told a different number.
     */
    public function overallProgressPercent(Client $client): int
    {
        return $this->progress->percentFor($client);
    }

    public function currentStage(Client $client): ?array
    {
        foreach ($this->present($client) as $stage) {
            if ($stage['current']) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Client-visible work items, newest first, with everything the timeline
     * needs already loaded.
     *
     * @return Collection<int,FlowItem>
     */
    public function items(Client $client): Collection
    {
        return FlowItem::query()
            ->where('client_id', $client->id)
            ->where('status', '!=', FlowItem::STATUS_CANCELLED)
            ->whereHas('flow', fn ($q) => $q->where('client_visible', true))
            ->with([
                'flow:id,name,description,client_visible',
                'flow.stages:id,flow_id,name,position',
                'currentStage:id,name,position',
                'transitions:id,flow_item_id,from_stage_id,to_stage_id,created_at',
            ])
            ->orderBy('id')
            ->get();
    }

    /** One stage of one item, as the portal should see it. */
    private function entry(FlowItem $item, FlowStage $stage): array
    {
        $currentPosition = $item->currentStage->position ?? null;
        $isCompleted     = $item->status === FlowItem::STATUS_COMPLETED;

        $done = $isCompleted || ($currentPosition !== null && $stage->position < $currentPosition);
        $current = !$isCompleted && $item->current_stage_id === $stage->id;

        $status = match (true) {
            $done    => 'Approved',
            $current => 'In Progress',
            default  => 'Pending',
        };

        return [
            'id'                       => $stage->id,
            'item_id'                  => $item->id,
            'item_title'               => $item->title,
            'service'                  => $item->flow->name ?? null,
            'name'                     => $stage->name,
            'description'              => null,
            'status'                   => $status,
            'progress_percent'         => $done ? 100 : ($current ? 50 : 0),
            'started_at'               => $this->arrivedAt($item, $stage),
            'completed_at'             => $done ? $this->leftAt($item, $stage) : null,
            // The flow engine keeps no per-stage client copy; client-facing
            // narrative lives in Project Updates instead.
            'client_update'            => null,
            'next_step'                => null,
            'client_action_required'   => false,
            'client_approval_required' => false,
            // Work cannot skip ahead, so anything past the current stage is
            // not yet reachable.
            'locked'                   => !$done && !$current,
            'current'                  => $current,
            'overdue'                  => $current && $item->isOverdue(),
        ];
    }

    /** When the item arrived at this stage, from its transition history. */
    private function arrivedAt(FlowItem $item, FlowStage $stage)
    {
        $arrival = $item->transitions->firstWhere('to_stage_id', $stage->id);

        // The opening stage is where an item starts; nothing moved it there.
        if (!$arrival && $stage->position === 1) {
            return $item->created_at;
        }

        return $arrival?->created_at;
    }

    /** When the item moved on from this stage. */
    private function leftAt(FlowItem $item, FlowStage $stage)
    {
        $departure = $item->transitions->firstWhere('from_stage_id', $stage->id);

        return $departure?->created_at ?? ($item->status === FlowItem::STATUS_COMPLETED ? $item->completed_at : null);
    }
}
