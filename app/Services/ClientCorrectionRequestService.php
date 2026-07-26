<?php

namespace App\Services;

use App\Models\ClientCorrectionRequest;
use App\Models\User;

class ClientCorrectionRequestService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Records the review decision. Never writes to the Client/other target
     * record automatically — staff apply the change through the normal
     * module (e.g. ClientService::update()) themselves, then this request
     * is marked applied_at for audit purposes.
     */
    public function respond(ClientCorrectionRequest $request, string $status, ?string $note, User $actor): ClientCorrectionRequest
    {
        $request->update([
            'status'      => $status,
            'review_note' => $note,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        $this->activityLog->log('Correction Request', "Request {$status}", $request->client_id, null, ['field' => $request->field_label]);

        return $request;
    }

    public function markApplied(ClientCorrectionRequest $request): ClientCorrectionRequest
    {
        $request->update(['applied_at' => now()]);
        $this->activityLog->log('Correction Request', 'Applied to record', $request->client_id, null, ['field' => $request->field_label]);

        return $request;
    }
}
