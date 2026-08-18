<?php

namespace App\Notifications;

use App\Models\ClientCorrectionRequest;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

class CorrectionRequestSubmitted extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly ClientCorrectionRequest $correctionRequest,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $client = $this->correctionRequest->client;

        return [
            'title'   => 'Client requested an information correction',
            'message' => "{$client->client_name} requested a correction to {$this->correctionRequest->field_label}.",
            'url'     => route('clients.show', $client),
        ];
    }
}
