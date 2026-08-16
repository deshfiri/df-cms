<?php

namespace App\Services;

use App\Models\EmployeeRequest;
use App\Models\User;
use App\Notifications\RequestResolved;
use App\Notifications\RequestSubmitted;
use App\Services\Concerns\NotifiesStaff;
use Illuminate\Support\Facades\DB;

class EmployeeRequestService
{
    use NotifiesStaff;

    private const APPROVER_ROLES = ['Super Admin', 'Manager'];

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(array $data, User $actor): EmployeeRequest
    {
        return DB::transaction(function () use ($data, $actor) {
            $data['requested_by'] = $actor->id;
            $data['status']       = EmployeeRequest::STATUS_PENDING;

            $request = EmployeeRequest::create($data);

            $this->activityLog->log(
                'Request',
                'Submitted',
                $request->client_id,
                null,
                ['subject' => $request->subject]
            );

            $this->notifyApprovers($request, $actor);

            return $request->load('requestedBy:id,name', 'client:id,client_name');
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

    /** Approvers who can actually action the request — never the person who filed it. */
    private function notifyApprovers(EmployeeRequest $request, User $actor): void
    {
        $this->notifyStaff(
            self::APPROVER_ROLES,
            new RequestSubmitted($request),
            permission: 'manage requests',
            except: $actor,
        );
    }
}
