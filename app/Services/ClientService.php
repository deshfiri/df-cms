<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Repositories\Contracts\ClientRepositoryInterface;
use App\Repositories\Contracts\WorkflowRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ClientService
{
    /**
     * Client fields a non-privileged edit must have approved.
     *
     * Ownership decides who sees and works the account, status decides whether
     * it is still live, and category drives reporting — a quiet change to any
     * of them is worth a second pair of eyes. Editing an address or a phone
     * number is not, and gating those simply stopped people doing their job.
     */
    private const APPROVAL_FIELDS = ['assigned_to', 'client_status', 'category_id'];

    public function __construct(
        private readonly ClientRepositoryInterface   $clientRepo,
        private readonly WorkflowRepositoryInterface $workflowRepo,
        private readonly ActivityLogService          $activityLog,
        private readonly ChangeApprovalService        $changeApproval,
    ) {}

    public function create(array $data): Client
    {
        return DB::transaction(function () use ($data) {
            $data['dfid_number'] ??= $this->clientRepo->nextDfidNumber();
            $data['created_by']    = Auth::id();
            $data['updated_by']    = Auth::id();

            if (array_key_exists('customer_reason', $data)) {
                $data['customer_reason'] = $this->normalizeReason($data['customer_reason']);
            }

            $client = $this->clientRepo->create($data);
            $this->workflowRepo->initClientStages($client->id);

            $this->activityLog->log('Client', 'Created', $client->id, null, $client->toArray());
            $this->logReasonChange($client->id, null, $data['customer_reason'] ?? null, Auth::id());

            return $client;
        });
    }

    /**
     * $actor is who the change is credited to — the person who actually
     * made the edit. It defaults to the current session, but a caller
     * replaying someone else's already-approved change (see
     * PendingChangeController::approve()) passes that person explicitly, so
     * `updated_by` and the activity log correctly name them rather than
     * whichever approver happened to click the button. $changeApproval is
     * always guarded against the real current session regardless — its
     * privilege check is what decides whether this bypasses review at all,
     * so it must never be swapped for the original requester.
     */
    public function update(Client $client, array $data, ?User $actor = null): Client
    {
        $actor ??= Auth::user();

        if (array_key_exists('customer_reason', $data)) {
            $data['customer_reason'] = $this->normalizeReason($data['customer_reason']);
        }

        if (($data['client_status'] ?? null) === 'Terminated' && $client->client_status !== 'Terminated') {
            $this->guardTermination();
        }

        $this->changeApproval->guardFields(
            Client::class,
            $client->id,
            $client->only(array_keys($data)),
            $data,
            Auth::user(),
            self::APPROVAL_FIELDS,
        );

        return DB::transaction(function () use ($client, $data, $actor) {
            $old = $client->only(array_keys($data));

            $updated = $this->clientRepo->update($client, array_merge($data, ['updated_by' => $actor->id]));
            $this->activityLog->log('Client', 'Updated', $client->id, $old, $data, actorId: $actor->id);

            if (array_key_exists('customer_reason', $data)) {
                $this->logReasonChange($client->id, $old['customer_reason'] ?? null, $data['customer_reason'], $actor->id);
            }

            return $updated;
        });
    }

    /**
     * The inline edit on the clients list. Same column and same history entry
     * as the edit form; saving an unchanged value writes nothing.
     */
    public function updateCustomerReason(Client $client, ?string $reason): Client
    {
        $reason = $this->normalizeReason($reason);
        $old    = $this->normalizeReason($client->customer_reason);

        if ($old === $reason) {
            return $client;
        }

        return DB::transaction(function () use ($client, $reason, $old) {
            $updated = $this->clientRepo->update($client, [
                'customer_reason' => $reason,
                'updated_by'      => Auth::id(),
            ]);
            $this->logReasonChange($client->id, $old, $reason, Auth::id());

            return $updated;
        });
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : $reason;
    }

    private function logReasonChange(int $clientId, ?string $old, ?string $new, ?int $actorId): void
    {
        $old = $this->normalizeReason($old);

        if ($old === $new) {
            return;
        }

        $this->activityLog->log('Client', 'Customer Reason Changed', $clientId, $old, $new, actorId: $actorId);
    }

    public function delete(Client $client): void
    {
        DB::transaction(function () use ($client) {
            $this->activityLog->log('Client', 'Deleted', $client->id, $client->toArray());
            $this->clientRepo->delete($client);
        });
    }

    public function updateStatus(Client $client, string $status): Client
    {
        if ($status === 'Terminated' && $client->client_status !== 'Terminated') {
            $this->guardTermination();
        }

        // client_status is a watched field, so this always needs approval from a
        // non-privileged user — routed through the same check for one rule.
        $this->changeApproval->guardFields(
            Client::class,
            $client->id,
            ['client_status' => $client->client_status],
            ['client_status' => $status],
            Auth::user(),
            self::APPROVAL_FIELDS,
        );

        $old = $client->client_status;
        $updated = $this->clientRepo->update($client, [
            'client_status' => $status,
            'updated_by'    => Auth::id(),
        ]);
        $this->activityLog->log('Client', 'Status Changed', $client->id, $old, $status);

        return $updated;
    }

    /**
     * Setting a client to Terminated permanently locks its workflow, so it's
     * restricted to Super Admin/Manager regardless of which path (single
     * status change, bulk terminate, or the general edit form) is used.
     */
    private function guardTermination(): void
    {
        if (!Auth::user()->can('terminate', Client::class)) {
            throw new AuthorizationException('Only Super Admin or Manager can terminate a client.');
        }
    }

    public function getDashboardData(): array
    {
        return [
            'status_counts' => $this->clientRepo->statusCounts(),
            'recent'        => $this->clientRepo->recentlyJoined(8),
            'total'         => array_sum($this->clientRepo->statusCounts()),
        ];
    }
}
