<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsAppConversation;

/**
 * Server-side gate for every WhatsApp conversation.
 *
 * Two questions, always in this order: may this person touch WhatsApp at all,
 * and may they touch *this* conversation. The second is what keeps one brand's
 * customers away from another's, and it is never left to the frontend.
 *
 * Viewing and replying are deliberately independent (spec §27): an agent may be
 * given read access to their queue without the right to answer a customer.
 *
 * Super Admin short-circuits through Gate::before, so this only decides for
 * everyone else.
 */
class WhatsAppConversationPolicy
{
    /** Open the inbox at all. */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['view whatsapp', 'view all whatsapp']);
    }

    /**
     * Read one conversation.
     *
     * Mirrors WhatsAppConversation::scopeVisibleTo exactly. The two must agree:
     * the scope keeps unauthorised rows out of listings, this keeps them out of
     * direct access, and a divergence between them is a hole.
     */
    public function view(User $user, WhatsAppConversation $conversation): bool
    {
        if (!$user->can('view whatsapp') && !$user->can('view all whatsapp')) {
            return false;
        }

        if ($user->can('view all whatsapp')) {
            return true;
        }

        // Assignment is the only other route in. An unassigned conversation is
        // invisible to an ordinary agent until someone hands it over (spec §31).
        return $conversation->assigned_user_id === $user->id;
    }

    /**
     * Send a message.
     *
     * Everything that must be true before a customer hears from us: the agent
     * can see the thread, holds the reply permission, the conversation is still
     * open, and the number behind it can actually send.
     */
    public function reply(User $user, WhatsAppConversation $conversation): bool
    {
        return $this->view($user, $conversation)
            && $user->can('reply whatsapp')
            && !$conversation->isClosed()
            && (bool) $conversation->account?->isUsable();
    }

    /** Why a reply is refused, in words worth showing an agent. */
    public function replyRefusalReason(User $user, WhatsAppConversation $conversation): ?string
    {
        if ($this->reply($user, $conversation)) {
            return null;
        }

        return match (true) {
            !$this->view($user, $conversation)  => 'You do not have access to this conversation.',
            !$user->can('reply whatsapp')       => 'You have read-only access to WhatsApp conversations.',
            $conversation->isClosed()           => 'This conversation is closed. Reopen it to reply.',
            default => $conversation->account?->unusableReason()
                ?? 'This conversation cannot be replied to right now.',
        };
    }

    public function assign(User $user, WhatsAppConversation $conversation): bool
    {
        // Handing out access is an administrative act: it is gated on the
        // assign permission plus global visibility, so an agent cannot pass
        // their own conversation to someone who should not see it.
        return $user->can('assign whatsapp') && $user->can('view all whatsapp');
    }

    public function changeStatus(User $user, WhatsAppConversation $conversation): bool
    {
        return $this->view($user, $conversation)
            && ($user->can('assign whatsapp') || $user->can('reply whatsapp'));
    }
}
