<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\FlowItem;

class PortalServiceGroupingService
{
    public function __construct(
        private readonly PortalJourneyPresenter $journeyPresenter,
    ) {}

    /**
     * Presents each piece of client-visible work as a "service" card, with its
     * own progress, current step and last update.
     *
     * Grouped by flow rather than by department: on the retired pipeline every
     * client ran one shared stage list, so department was the only way to tell
     * two streams of work apart. A flow already *is* a stream of work, and a
     * client can be running two of the same kind, so each item gets its card.
     */
    public function groupByDepartment(Client $client): array
    {
        return $this->journeyPresenter->items($client)->map(function (FlowItem $item) {
            $stages = $item->flow?->stages ?? collect();
            $total  = $stages->count();

            $currentPosition = $item->currentStage->position ?? null;
            $isCompleted     = $item->status === FlowItem::STATUS_COMPLETED;

            $done = $isCompleted
                ? $total
                : max(0, min($total, ($currentPosition ?? 1) - 1));

            $progress = $total > 0 ? (int) round(($done / $total) * 100) : 0;

            $status = match (true) {
                $isCompleted || ($total > 0 && $progress === 100) => 'Completed',
                $progress === 0                                   => 'Not Started',
                default                                           => 'Active',
            };

            return [
                // The flow names the service; the item names this instance of it.
                'department'    => $item->flow->name ?? 'Work',
                'title'         => $item->title,
                'status'        => $status,
                'progress'      => $progress,
                'current_stage' => $isCompleted ? null : ($item->currentStage->name ?? null),
                'next_step'     => null,
                'last_update'   => $item->updated_at,
                'stage_count'   => $total,
            ];
        })->all();
    }
}
