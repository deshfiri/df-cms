<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientPortalAccountService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(Client $client, array $data, User $actor): ClientPortalUser
    {
        return DB::transaction(function () use ($client, $data, $actor) {
            $portalUser = ClientPortalUser::create(array_merge($data, [
                'client_id'  => $client->id,
                'password'   => $data['password'] ?? Str::random(16),
                'status'     => ClientPortalUser::STATUS_ACTIVE,
                'created_by' => $actor->id,
            ]));

            $this->activityLog->log('Client Portal Account', 'Created', $client->id, null, ['name' => $portalUser->name]);

            return $portalUser;
        });
    }

    public function updateStatus(ClientPortalUser $portalUser, string $status): ClientPortalUser
    {
        $portalUser->update(['status' => $status]);
        $this->activityLog->log('Client Portal Account', "Status changed to {$status}", $portalUser->client_id, null, ['portal_user_id' => $portalUser->id]);

        return $portalUser;
    }

    public function resetPassword(ClientPortalUser $portalUser, string $newPassword): ClientPortalUser
    {
        $portalUser->update(['password' => $newPassword]);
        $this->activityLog->log('Client Portal Account', 'Password reset by staff', $portalUser->client_id, null, ['portal_user_id' => $portalUser->id]);

        return $portalUser;
    }
}
