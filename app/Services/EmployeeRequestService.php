<?php

namespace App\Services;

use App\Models\EmployeeRequest;
use App\Models\User;
use App\Notifications\RequestResolved;
use App\Notifications\RequestSubmitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

class EmployeeRequestService
{
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

            $this->notifyApprovers($request);

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

        $request->requestedBy?->notify(new RequestResolved($request));

        return $request->fresh();
    }

    public function delete(EmployeeRequest $request): void
    {
        $this->activityLog->log('Request', 'Deleted', $request->client_id, ['subject' => $request->subject]);
        $request->delete();
    }

    private function notifyApprovers(EmployeeRequest $request): void
    {
        // User::role() throws if ANY given role name doesn't exist at all
        // (rather than just finding nobody) — filter to roles that actually
        // exist first, so a missing role can never blow up this transaction.
        $existingRoles = Role::whereIn('name', self::APPROVER_ROLES)->pluck('name')->all();
        if (empty($existingRoles)) {
            return;
        }

        $approvers = User::role($existingRoles)->where('is_active', true)->get();
        if ($approvers->isEmpty()) {
            return;
        }

        Notification::send($approvers, new RequestSubmitted($request));
    }
}
