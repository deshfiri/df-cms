<?php

namespace App\Notifications;

use App\Models\ClientOwnershipTransfer;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ClientOwnershipTransferred extends Notification
{
    public function __construct(
        private readonly ClientOwnershipTransfer $transfer,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $client = $this->transfer->client;
        $from   = $this->transfer->previousOwner?->name ?? 'Unassigned';
        $to     = $this->transfer->newOwner?->name ?? 'Unknown';
        $by     = $this->transfer->transferredBy?->name ?? 'Someone';
        $verb   = $this->transfer->previous_owner_id ? 'transferred' : 'assigned';

        return [
            'title'       => $this->transfer->previous_owner_id ? 'Client transferred' : 'Client assigned',
            'message'     => "{$by} {$verb} {$client->client_name} from {$from} to {$to}.",
            'client_id'   => $client->id,
            'transfer_id' => $this->transfer->id,
            'url'         => route('clients.show', $client),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $data = $this->toDatabase($notifiable);

        return (new MailMessage)
            ->subject("{$data['title']}: {$this->transfer->client->client_name}")
            ->line($data['message'])
            ->action('View Client', $data['url']);
    }
}
