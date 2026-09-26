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

    /**
     * Records $actor's own answer, then re-derives the request's overall
     * status from every recipient's: Rejected the moment any one of them
     * rejects, Approved only once every recipient has, Pending otherwise. A
     * request with several recipients is only ever "resolved" — and the
     * requester only ever notified — once it lands on one of those two.
     */
    public function respond(EmployeeRequest $request, string $status, ?string $note, User $actor): EmployeeRequest
    {
        return DB::transaction(function () use ($request, $status, $note, $actor) {
            $locked = EmployeeRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            $locked->recipients()->updateExistingPivot($actor->id, [
                'status'       => $status,
                'note'         => $note,
                'responded_at' => now(),
            ]);
            $locked->load('recipients');

            $pivotStatuses = $locked->recipients->pluck('pivot.status');
            $overall = match (true) {
                $pivotStatuses->contains(EmployeeRequest::STATUS_REJECTED) => EmployeeRequest::STATUS_REJECTED,
                $pivotStatuses->every(fn ($s) => $s === EmployeeRequest::STATUS_APPROVED) => EmployeeRequest::STATUS_APPROVED,
                default => EmployeeRequest::STATUS_PENDING,
            };
            $wasPending = $locked->status === EmployeeRequest::STATUS_PENDING;

            // response_note/reviewed_by only ever describe the response that
            // actually settled it — while other recipients are still
            // pending, this recipient's own note is theirs alone (on their
            // own pivot row above), not something to surface to anyone else.
            $locked->status = $overall;
            if ($overall !== EmployeeRequest::STATUS_PENDING) {
                $locked->response_note = $note;
                $locked->reviewed_by   = $actor->id;
                $locked->reviewed_at   = now();
            }
            $locked->save();

            $this->activityLog->log(
                'Request',
                "Request {$status}",
                $locked->client_id,
                null,
                ['subject' => $locked->subject, 'by' => $actor->id, 'overall' => $overall]
            );

            // Only once it's actually settled — a partial approval on a
            // multi-recipient request isn't "resolved" yet.
            if ($wasPending && $overall !== EmployeeRequest::STATUS_PENDING
                && $locked->requestedBy && $locked->requested_by !== $actor->id) {
                $locked->requestedBy->notify(new RequestResolved($locked));
            }

            return $locked->fresh(['recipients']);
        });
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
