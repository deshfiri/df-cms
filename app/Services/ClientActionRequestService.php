<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientActionRequest;
use App\Models\User;
use App\Notifications\Portal\ActionRequested;
use App\Services\Portal\NotifiesPortalUsers;
use Illuminate\Support\Facades\DB;

class ClientActionRequestService
{
    use NotifiesPortalUsers;

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(Client $client, array $data, User $actor): ClientActionRequest
    {
        return DB::transaction(function () use ($client, $data, $actor) {
            $actionRequest = ClientActionRequest::create(array_merge($data, [
                'client_id'     => $client->id,
                'requested_by'  => $actor->id,
                'status'        => ClientActionRequest::STATUS_PENDING,
            ]));

            $this->activityLog->log('Client Action', 'Requested', $client->id, null, ['title' => $actionRequest->title]);
            $this->notifyPortalUsers($client, new ActionRequested($actionRequest));

            return $actionRequest;
        });
    }

    public function review(ClientActionRequest $actionRequest, string $status, ?string $feedback, User $actor): ClientActionRequest
    {
        $actionRequest->update([
            'status'        => $status,
            'team_feedback' => $feedback,
            'reviewed_by'   => $actor->id,
            'reviewed_at'   => now(),
        ]);

        $this->activityLog->log('Client Action', "Reviewed: {$status}", $actionRequest->client_id, null, ['title' => $actionRequest->title]);

        if ($status === ClientActionRequest::STATUS_NEED_REVISION) {
            $this->notifyPortalUsers($actionRequest->client, new ActionRequested($actionRequest));
        }

        return $actionRequest;
    }
}
