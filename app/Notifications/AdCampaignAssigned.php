<?php

namespace App\Notifications;

use App\Models\AdCampaignAssignment;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class AdCampaignAssigned extends Notification
{
    public function __construct(
        private readonly AdCampaignAssignment $assignment,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $campaign = $this->assignment->campaign;
        $client   = $campaign->client;
        $from     = $this->assignment->previousAssignee?->name ?? 'Unassigned';
        $to       = $this->assignment->newAssignee?->name ?? 'Unknown';
        $by       = $this->assignment->assignedBy?->name ?? 'Someone';
        $verb     = $this->assignment->previous_assignee_id ? 'reassigned' : 'assigned';

        return [
            'title'       => $this->assignment->previous_assignee_id ? 'Campaign reassigned' : 'Campaign assigned',
            'message'     => "{$by} {$verb} \"{$campaign->name}\" ({$client->client_name}) from {$from} to {$to}.",
            'client_id'   => $client->id,
            'campaign_id' => $campaign->id,
            'url'         => route('clients.ads.show', [$client, $campaign]),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $data = $this->toDatabase($notifiable);

        return (new MailMessage)
            ->subject("{$data['title']}: {$this->assignment->campaign->name}")
            ->line($data['message'])
            ->action('View Campaign', $data['url']);
    }
}
