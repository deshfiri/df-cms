<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientOwnershipTransfer;
use App\Models\User;
use App\Notifications\ClientOwnershipTransferred;
use App\Repositories\Contracts\ClientRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

class ClientOwnershipService
{
    private const NOTIFY_ROLES = ['Super Admin'];

    public function __construct(
        private readonly ClientRepositoryInterface $clientRepo,
        private readonly ActivityLogService        $activityLog,
    ) {}

    /**
     * Assigns or reassigns a client's owner. Deliberately independent of
     * ClientService::update()/ChangeApprovalService — an ownership change is
     * an admin/owner management action that applies immediately once the
     * transfer/bulkAssign policy check passes, not a field edit subject to
     * the pending-approval review queue.
     */
    public function transfer(Client $client, User $newOwner, User $actor, ?string $note = null): Client
    {
        return DB::transaction(function () use ($client, $newOwner, $actor, $note) {
            $previousOwnerId = $client->assigned_to;

            $updated = $this->clientRepo->update($client, [
                'assigned_to' => $newOwner->id,
                'updated_by'  => $actor->id,
            ]);

            $record = ClientOwnershipTransfer::create([
                'client_id'         => $client->id,
                'previous_owner_id' => $previousOwnerId,
                'new_owner_id'      => $newOwner->id,
                'transferred_by'    => $actor->id,
                'note'              => $note,
            ]);

            $this->activityLog->log(
                'Client',
                $previousOwnerId ? 'Ownership Transferred' : 'Ownership Assigned',
                $client->id,
                ['assigned_to' => $previousOwnerId],
                ['assigned_to' => $newOwner->id, 'note' => $note]
            );

            $this->notify($record, $newOwner);

            return $updated;
        });
    }

    public function bulkAssign(array $clientIds, User $newOwner, User $actor, ?string $note = null): int
    {
        $count = 0;

        Client::whereIn('id', $clientIds)->get()->each(function (Client $client) use ($newOwner, $actor, $note, &$count) {
            $this->transfer($client, $newOwner, $actor, $note);
            $count++;
        });

        return $count;
    }

    private function notify(ClientOwnershipTransfer $record, User $newOwner): void
    {
        $existingRoles = Role::whereIn('name', self::NOTIFY_ROLES)->pluck('name')->all();
        $recipients = $existingRoles ? User::role($existingRoles)->where('is_active', true)->get() : collect();

        if (!$recipients->contains('id', $newOwner->id)) {
            $recipients->push($newOwner);
        }

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ClientOwnershipTransferred($record));
        }
    }
}
