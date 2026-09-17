<?php

namespace App\Notifications;

use App\Models\Refund;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

/**
 * A refund moved and somebody has something to do, or should know:
 * requested (approvers decide), approved (processors pay out; the requester
 * hears), rejected / completed (the requester hears).
 */
class RefundNeedsAttention extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly Refund $refund,
        private readonly string $what,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $amount = '৳' . number_format((float) $this->refund->amount, 2);
        $number = $this->refund->refund_number;
        $client = $this->refund->client?->client_name ?? 'a client';

        [$title, $message] = match ($this->what) {
            'requested' => ['Refund waiting for approval', "{$number}: {$amount} for {$client} needs a decision."],
            'approved'  => ['Refund approved', "{$number}: {$amount} for {$client} is approved and ready to pay out."],
            'rejected'  => ['Refund rejected', "{$number}: {$amount} for {$client} was rejected."],
            'completed' => ['Refund paid out', "{$number}: {$amount} for {$client} has been paid back."],
            default     => ['Refund updated', "{$number} is now " . strtolower($this->refund->status_label) . '.'],
        };

        return [
            'title'     => $title,
            'message'   => $message,
            'client_id' => $this->refund->client_id,
            'refund_id' => $this->refund->id,
            'url'       => route('refunds.index', ['refund' => $this->refund->id]),
        ];
    }
}
