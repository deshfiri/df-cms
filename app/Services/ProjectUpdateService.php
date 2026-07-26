<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientProjectUpdate;
use App\Models\User;
use App\Notifications\Portal\ProjectUpdatePosted;
use App\Services\Portal\NotifiesPortalUsers;
use Illuminate\Support\Facades\DB;

class ProjectUpdateService
{
    use NotifiesPortalUsers;

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(Client $client, array $data, User $actor): ClientProjectUpdate
    {
        return DB::transaction(function () use ($client, $data, $actor) {
            $update = ClientProjectUpdate::create(array_merge($data, [
                'client_id' => $client->id,
                'posted_by' => $actor->id,
            ]));

            $this->activityLog->log('Project Update', 'Posted', $client->id, null, ['title' => $update->title]);

            if ($update->is_client_visible) {
                $this->notifyPortalUsers($client, new ProjectUpdatePosted($update));
            }

            return $update;
        });
    }

    public function delete(ClientProjectUpdate $update): void
    {
        $this->activityLog->log('Project Update', 'Deleted', $update->client_id, ['title' => $update->title]);
        $update->delete();
    }
}
