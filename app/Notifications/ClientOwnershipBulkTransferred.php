<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;

class ClientOwnershipBulkTransferred extends Notification
{
    private const PREVIEW_COUNT = 5;

    /**
     * @param Collection<int, string> $clientNames
     */
    public function __construct(
        private readonly Collection $clientNames,
        private readonly User $newOwner,
        private readonly User $actor,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $count   = $this->clientNames->count();
        $preview = $this->clientNames->take(self::PREVIEW_COUNT)->implode(', ');
        $extra   = $count - self::PREVIEW_COUNT;

        $message = "{$this->actor->name} assigned {$count} clients to {$this->newOwner->name}: {$preview}"
            . ($extra > 0 ? " and {$extra} more." : '.');

        return [
            'title'   => "{$count} clients assigned",
            'message' => $message,
            'url'     => route('clients.index', ['assigned_to' => $this->newOwner->id]),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $data = $this->toDatabase($notifiable);

        return (new MailMessage)
            ->subject($data['title'])
            ->line($data['message'])
            ->action('View Clients', $data['url']);
    }
}
