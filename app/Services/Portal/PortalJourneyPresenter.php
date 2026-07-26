<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Services\WorkflowService;

class PortalJourneyPresenter
{
    public function __construct(
        private readonly WorkflowService $workflowService,
    ) {}

    /**
     * Client-safe view of the workflow timeline: only client_visible stages,
     * and only the fields a client should ever see. Internal-only data
     * (remarks, rejection_reason, submitted_by, completed_by, department)
     * never leaves this method.
     */
    public function present(Client $client): array
    {
        $timeline = $this->workflowService->getTimeline($client);

        $visible = array_values(array_filter($timeline, fn (array $entry) => $entry['stage']->is_client_visible));

        return array_map(function (array $entry) {
            $stage = $entry['stage'];
            $progress = $entry['progress'];

            return [
                'id'                      => $stage->id,
                'name'                    => $stage->name,
                'description'             => $stage->description,
                'status'                  => $entry['status'],
                'progress_percent'        => $entry['status'] === 'Approved' ? 100 : ($entry['current'] ? 50 : 0),
                'started_at'              => $progress?->submitted_at,
                'completed_at'            => $progress?->completed_at,
                'client_update'           => $progress?->client_update_text,
                'next_step'               => $progress?->next_step,
                'client_action_required'  => (bool) $progress?->client_action_required,
                'client_approval_required'=> (bool) $progress?->client_approval_required,
                'locked'                  => $entry['locked'],
                'current'                 => $entry['current'],
                'overdue'                 => $entry['overdue'],
            ];
        }, $visible);
    }

    public function overallProgressPercent(Client $client): int
    {
        $stages = $this->present($client);
        if (empty($stages)) {
            return 0;
        }
        $approved = count(array_filter($stages, fn ($s) => $s['status'] === 'Approved'));

        return (int) round(($approved / count($stages)) * 100);
    }

    public function currentStage(Client $client): ?array
    {
        $stages = $this->present($client);
        foreach ($stages as $stage) {
            if ($stage['current']) {
                return $stage;
            }
        }

        return null;
    }
}
