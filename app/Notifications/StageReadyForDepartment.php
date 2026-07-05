<?php

namespace App\Notifications;

use App\Models\Client;
use App\Models\WorkflowStage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class StageReadyForDepartment extends Notification
{
    public function __construct(
        private readonly Client $client,
        private readonly WorkflowStage $previousStage,
        private readonly WorkflowStage $nextStage,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'      => "{$this->previousStage->name} approved",
            'message'    => "{$this->client->client_name} is ready for {$this->nextStage->name} ({$this->nextStage->department} team).",
            'client_id'  => $this->client->id,
            'stage_id'   => $this->nextStage->id,
            'url'        => route('clients.show', $this->client),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Ready for {$this->nextStage->name}: {$this->client->client_name}")
            ->line("{$this->previousStage->name} has been approved for {$this->client->client_name}.")
            ->line("The next step, \"{$this->nextStage->name}\", is now unlocked for the {$this->nextStage->department} team.")
            ->action('View Client', route('clients.show', $this->client));
    }
}
