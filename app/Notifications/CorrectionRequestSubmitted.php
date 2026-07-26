<?php

namespace App\Notifications;

use App\Models\ClientCorrectionRequest;
use Illuminate\Notifications\Notification;

class CorrectionRequestSubmitted extends Notification
{
    public function __construct(
        private readonly ClientCorrectionRequest $correctionRequest,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
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
