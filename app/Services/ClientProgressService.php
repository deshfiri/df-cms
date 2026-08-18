<?php

namespace App\Services;

use App\Models\Client;
use App\Models\FlowItem;
use Illuminate\Support\Collection;

/**
 * How far a client's work has actually got.
 *
 * Measured against the flows an admin built: every stage of every flow the
 * client's items are running is one unit of work, and an item that has moved
 * past a stage has finished it. A client running a 4-stage flow and a 6-stage
 * flow is 20% done once the first item reaches stage 3.
 *
 * This exists because the same percentage used to be re-derived in three
 * places against the retired WorkflowStage pipeline, each with a slightly
 * different formula — one of which could report over 100% once a stage was
 * deactivated, because it counted completions against only the *active*
 * stages. There is now one definition.
 */
class ClientProgressService
{
    /**
     * @return array{done:int,total:int,percent:int,items:int}
     */
    public function breakdownFor(Client $client): array
    {
        $done = 0;
        $total = 0;
        $counted = 0;

        foreach ($this->itemsFor($client) as $item) {
            // A cancelled item is abandoned work, not outstanding work; leaving
            // it in the denominator would peg the client below 100% forever.
            if ($item->status === FlowItem::STATUS_CANCELLED) {
                continue;
            }

            $stageCount = $item->flow?->stages->count() ?? 0;

            // A flow with no stages has no work to measure.
            if ($stageCount === 0) {
                continue;
            }

            $counted++;
            $total += $stageCount;

            // Position is 1-based, so an item sitting on stage 3 has cleared 2.
            $done += $item->status === FlowItem::STATUS_COMPLETED
                ? $stageCount
                : max(0, min($stageCount, ($item->currentStage->position ?? 1) - 1));
        }

        return [
            'done'    => $done,
            'total'   => $total,
            'percent' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
            'items'   => $counted,
        ];
    }

    public function percentFor(Client $client): int
    {
        return $this->breakdownFor($client)['percent'];
    }

    /**
     * Uses the already-loaded relation when the caller eager-loaded it — which
     * is what keeps a 25-row client table from firing 75 queries.
     */
    private function itemsFor(Client $client): Collection
    {
        if ($client->relationLoaded('flowItems')) {
            return $client->flowItems;
        }

        return $client->flowItems()->with(self::EAGER_LOAD)->get();
    }

    /** What breakdownFor() needs loaded to avoid per-item queries. */
    public const EAGER_LOAD = [
        'flow:id',
        'flow.stages:id,flow_id,position',
        'currentStage:id,position',
    ];
}
