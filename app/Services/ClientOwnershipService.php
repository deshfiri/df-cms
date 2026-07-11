<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientOwnershipTransfer;
use App\Models\User;
use App\Notifications\ClientOwnershipBulkTransferred;
use App\Notifications\ClientOwnershipTransferred;
use App\Repositories\Contracts\ClientRepositoryInterface;
use Illuminate\Support\Collection;
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
    public function transfer(Client $client, User $newOwner, User $actor, ?string $note = null, bool $notify = true): Client
    {
        return DB::transaction(function () use ($client, $newOwner, $actor, $note, $notify) {
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

            if ($notify) {
                $this->notify($record, $newOwner);
            }

            return $updated;
        });
    }

    /**
     * Assigns many clients at once. Each client still gets its own
     * ClientOwnershipTransfer audit row, but recipients get a single
     * consolidated notification instead of one per client — otherwise
     * assigning 60+ clients floods the notification list with 60+ entries.
     */
    public function bulkAssign(array $clientIds, User $newOwner, User $actor, ?string $note = null): int
    {
        $clients = Client::whereIn('id', $clientIds)->get();

        $clients->each(function (Client $client) use ($newOwner, $actor, $note) {
            $this->transfer($client, $newOwner, $actor, $note, notify: false);
        });

        if ($clients->isNotEmpty()) {
            $this->notifyBulk($clients->pluck('client_name'), $newOwner, $actor);
        }

        return $clients->count();
    }

    private function notify(ClientOwnershipTransfer $record, User $newOwner): void
    {
        $recipients = $this->notifyRecipients($newOwner);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ClientOwnershipTransferred($record));
        }
    }

    private function notifyBulk(Collection $clientNames, User $newOwner, User $actor): void
    {
        $recipients = $this->notifyRecipients($newOwner);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ClientOwnershipBulkTransferred($clientNames, $newOwner, $actor));
        }
    }

    private function notifyRecipients(User $newOwner): Collection
    {
        $existingRoles = Role::whereIn('name', self::NOTIFY_ROLES)->pluck('name')->all();
        $recipients = $existingRoles ? User::role($existingRoles)->where('is_active', true)->get() : collect();

        if (!$recipients->contains('id', $newOwner->id)) {
            $recipients->push($newOwner);
        }

        return $recipients;
    }
}
