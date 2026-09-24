<?php

namespace App\Services;

use App\Models\EmployeeRequest;
use App\Models\User;
use App\Notifications\RequestResolved;
use App\Notifications\RequestSubmitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class EmployeeRequestService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(array $data, User $actor): EmployeeRequest
    {
        return DB::transaction(function () use ($data, $actor) {
            $recipientIds = $data['recipient_ids'];
            unset($data['recipient_ids']);
            $data['requested_by'] = $actor->id;
            $data['status']       = EmployeeRequest::STATUS_PENDING;

            $request = EmployeeRequest::create($data);
            $request->recipients()->sync($recipientIds);

            $this->activityLog->log(
                'Request',
                'Submitted',
                $request->client_id,
                null,
                ['subject' => $request->subject, 'recipient_ids' => $recipientIds]
            );

            $this->notifyRecipients($request, $actor);

            return $request->load('requestedBy:id,name', 'client:id,client_name', 'recipients:id,name');
        });
    }

    public function respond(EmployeeRequest $request, string $status, ?string $note, User $actor): EmployeeRequest
    {
        $request->update([
            'status'        => $status,
            'response_note' => $note,
            'reviewed_by'   => $actor->id,
            'reviewed_at'   => now(),
        ]);

        $this->activityLog->log(
            'Request',
            "Request {$status}",
            $request->client_id,
            null,
            ['subject' => $request->subject]
        );

        // An approver resolving their own request already knows the outcome.
        if ($request->requestedBy && $request->requested_by !== $actor->id) {
            $request->requestedBy->notify(new RequestResolved($request));
        }

        return $request->fresh();
    }

    public function delete(EmployeeRequest $request): void
    {
        $this->activityLog->log('Request', 'Deleted', $request->client_id, ['subject' => $request->subject]);
        $request->delete();
    }

    /** Only the people this was actually sent to — never the person who filed it. */
    private function notifyRecipients(EmployeeRequest $request, User $actor): void
    {
        $recipients = $request->recipients()->where('is_active', true)->whereKeyNot($actor->getKey())->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new RequestSubmitted($request));
        }
    }
}
