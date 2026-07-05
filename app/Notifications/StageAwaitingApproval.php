<?php

namespace App\Notifications;

use App\Models\Client;
use App\Models\WorkflowStage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class StageAwaitingApproval extends Notification
{
    public function __construct(
        private readonly Client $client,
        private readonly WorkflowStage $stage,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'      => "{$this->stage->name} awaiting approval",
            'message'    => "{$this->client->client_name} has submitted {$this->stage->name} — it needs your team's approval.",
            'client_id'  => $this->client->id,
            'stage_id'   => $this->stage->id,
            'url'        => route('clients.show', $this->client),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Approval needed: {$this->stage->name} for {$this->client->client_name}")
            ->line("{$this->client->client_name} has submitted \"{$this->stage->name}\" for approval.")
            ->action('Review', route('clients.show', $this->client));
    }
}
