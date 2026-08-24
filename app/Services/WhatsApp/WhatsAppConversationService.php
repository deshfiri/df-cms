<?php

namespace App\Services\WhatsApp;

use App\Events\WhatsApp\WhatsAppConversationUpdated;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Notifications\WhatsApp\WhatsAppConversationAssigned;
use App\Services\ActivityLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Owns the state of a WhatsApp thread: creation, counters, assignment, status.
 *
 * Kept apart from the inbound and outbound services so that "what a conversation
 * is" has one home, whether the change arrived from Meta or from an agent.
 */
class WhatsAppConversationService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {
    }

    /**
     * The thread for this contact on this number, creating it if new.
     *
     * brand_id is copied from the account rather than accepted from anywhere
     * else — it is what every inbox query authorises on, so it may only ever be
     * derived server-side from the number that received the message.
     */
    public function findOrCreate(WhatsAppAccount $account, WhatsAppContact $contact): WhatsAppConversation
    {
        $attributes = [
            'whatsapp_account_id' => $account->id,
            'whatsapp_contact_id' => $contact->id,
        ];

        try {
            return WhatsAppConversation::firstOrCreate($attributes, [
                'brand_id' => $account->brand_id,
                'status'   => WhatsAppConversation::STATUS_OPEN,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two webhook workers racing on a customer's first message. The
            // unique index decided; read back whichever won.
            return WhatsAppConversation::where($attributes)->firstOrFail();
        }
    }

    /**
     * Fold an incoming message into its conversation.
     *
     * The unread counter is incremented atomically rather than read-modify-written:
     * several messages can land at once, and a lost update here shows an agent
     * the wrong number of waiting replies.
     */
    public function recordIncoming(WhatsAppConversation $conversation, WhatsAppMessage $message): void
    {
        $conversation->forceFill([
            'last_message_at'          => $message->sent_at ?? now(),
            'last_message_preview'     => WhatsAppInboundService::preview($message),
            'last_customer_message_at' => $message->sent_at ?? now(),
        ])->save();

        $conversation->increment('unread_count');

        // A customer writing again reopens a closed thread; nobody wants a reply
        // disappearing because someone tidied up yesterday.
        if ($conversation->isClosed()) {
            $conversation->forceFill([
                'status'    => WhatsAppConversation::STATUS_OPEN,
                'closed_at' => null,
                'closed_by' => null,
            ])->save();
        }
    }

    /** Fold an outgoing message into its conversation. */
    public function recordOutgoing(WhatsAppConversation $conversation, WhatsAppMessage $message): void
    {
        $conversation->forceFill([
            'last_message_at'      => now(),
            'last_message_preview' => WhatsAppInboundService::preview($message),
        ])->save();
    }

    /** Clear the unread counter for an agent who is looking at the thread. */
    public function markRead(WhatsAppConversation $conversation): void
    {
        if ($conversation->unread_count === 0) {
            return;
        }

        $conversation->forceFill(['unread_count' => 0])->save();

        $this->broadcastUpdate($conversation);
    }

    // ── Assignment ───────────────────────────────────────────────────────

    /**
     * Hand a conversation to an agent, or take it back with a null assignee.
     *
     * Assignment is what grants an ordinary agent access to the thread at all
     * (see WhatsAppConversation::scopeVisibleTo), so it is audit-logged.
     */
    public function assign(WhatsAppConversation $conversation, ?User $assignee, User $actor): WhatsAppConversation
    {
        $previous = $conversation->assignee;

        DB::transaction(function () use ($conversation, $assignee, $actor) {
            $conversation->forceFill([
                'assigned_user_id' => $assignee?->id,
                'assigned_by'      => $assignee ? $actor->id : null,
                'assigned_at'      => $assignee ? now() : null,
            ])->save();
        });

        $this->activityLog->log(
            module: 'WhatsApp',
            action: $assignee ? 'Conversation Assigned' : 'Conversation Unassigned',
            clientId: null,
            oldValue: ['assignee' => $previous?->name],
            newValue: ['assignee' => $assignee?->name, 'conversation_id' => $conversation->id],
        );

        // Don't notify someone about work they just gave themselves.
        if ($assignee && $assignee->id !== $actor->id) {
            try {
                $assignee->notify(new WhatsAppConversationAssigned($conversation->fresh(['contact', 'brand']), $actor));
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->broadcastUpdate($conversation);

        return $conversation;
    }

    // ── Status ───────────────────────────────────────────────────────────

    /** Closing never deletes anything — it only changes what the inbox shows. */
    public function changeStatus(WhatsAppConversation $conversation, string $status, User $actor): WhatsAppConversation
    {
        $previous = $conversation->status;

        $conversation->forceFill([
            'status'    => $status,
            'closed_at' => $status === WhatsAppConversation::STATUS_CLOSED ? now() : null,
            'closed_by' => $status === WhatsAppConversation::STATUS_CLOSED ? $actor->id : null,
        ])->save();

        $this->activityLog->log(
            module: 'WhatsApp',
            action: 'Conversation ' . ucfirst($status),
            clientId: null,
            oldValue: ['status' => $previous],
            newValue: ['status' => $status, 'conversation_id' => $conversation->id],
        );

        $this->broadcastUpdate($conversation);

        return $conversation;
    }

    /** Total unread WhatsApp messages this user is responsible for. */
    public function unreadCountFor(User $user): int
    {
        return (int) WhatsAppConversation::query()
            ->visibleTo($user)
            ->sum('unread_count');
    }

    private function broadcastUpdate(WhatsAppConversation $conversation): void
    {
        try {
            broadcast(new WhatsAppConversationUpdated($conversation->fresh(['contact', 'brand', 'assignee'])));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
