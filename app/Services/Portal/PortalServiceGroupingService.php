<?php

namespace App\Services\Portal;

use App\Models\Client;

class PortalServiceGroupingService
{
    public function __construct(
        private readonly PortalJourneyPresenter $journeyPresenter,
    ) {}

    /**
     * Groups the client-visible workflow stages by department, presenting
     * each department as a "service" card: aggregate progress/status/next
     * step, derived entirely from ClientStageProgress — no separate catalog.
     */
    public function groupByDepartment(Client $client): array
    {
        $stages = $this->journeyPresenter->present($client);

        // Departments aren't stored on the client-safe presenter output
        // (department is internal), so re-fetch stage->department pairing.
        $stageModels = $client->stageProgress()->with('stage')->get()->keyBy('stage_id');

        $groups = [];
        foreach ($stages as $stage) {
            $stageModel = $stageModels->get($stage['id']);
            $department = $stageModel?->stage?->department ?? 'General';

            $groups[$department] ??= [
                'department'   => $department,
                'stages'       => [],
                'statuses'     => [],
            ];
            $groups[$department]['stages'][] = $stage;
            $groups[$department]['statuses'][] = $stage['status'];
        }

        return array_map(function (array $group) {
            $total = count($group['stages']);
            $approved = count(array_filter($group['statuses'], fn ($s) => $s === 'Approved'));
            $progress = $total > 0 ? (int) round(($approved / $total) * 100) : 0;

            $status = match (true) {
                $progress === 100 => 'Completed',
                $progress === 0   => 'Not Started',
                default           => 'Active',
            };

            $current = collect($group['stages'])->firstWhere('current', true);
            $lastCompleted = collect($group['stages'])->filter(fn ($s) => $s['status'] === 'Approved')->last();

            return [
                'department'    => $group['department'],
                'status'        => $status,
                'progress'      => $progress,
                'current_stage' => $current['name'] ?? null,
                'next_step'     => $current['next_step'] ?? null,
                'last_update'   => $lastCompleted['completed_at'] ?? null,
                'stage_count'   => $total,
            ];
        }, array_values($groups));
    }
}
