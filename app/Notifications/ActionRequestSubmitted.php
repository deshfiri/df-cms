<?php

namespace App\Notifications;

use App\Models\ClientActionRequest;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

class ActionRequestSubmitted extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly ClientActionRequest $actionRequest,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $client = $this->actionRequest->client;

        return [
            'title'   => 'Client submitted a pending action',
            'message' => "{$client->client_name} responded to \"{$this->actionRequest->title}\".",
            'url'     => route('clients.show', $client),
        ];
    }
}
