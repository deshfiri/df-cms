<?php

namespace App\Notifications;

use App\Models\PendingChange;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ChangeAwaitingApproval extends Notification
{
    public function __construct(
        private readonly PendingChange $pendingChange,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $label = class_basename($this->pendingChange->model_type);
        $requester = $this->pendingChange->requestedBy?->name ?? 'Someone';

        return [
            'title'             => 'Change awaiting approval',
            'message'           => "{$requester} requested a change to {$label} #{$this->pendingChange->model_id} that needs your approval.",
            'pending_change_id' => $this->pendingChange->id,
            'url'               => route('pending-changes.index'),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $label = class_basename($this->pendingChange->model_type);
        $requester = $this->pendingChange->requestedBy?->name ?? 'Someone';

        return (new MailMessage)
            ->subject("Change awaiting approval: {$label} #{$this->pendingChange->model_id}")
            ->line("{$requester} requested a change to {$label} #{$this->pendingChange->model_id}.")
            ->line('It has not been applied — it needs your review first.')
            ->action('Review Change', route('pending-changes.index'));
    }
}
