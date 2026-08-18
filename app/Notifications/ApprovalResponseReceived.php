<?php

namespace App\Notifications;

use App\Models\ClientApprovalRequest;
use App\Models\ClientApprovalResponse;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

class ApprovalResponseReceived extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly ClientApprovalRequest $approvalRequest,
        private readonly ClientApprovalResponse $response,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $client = $this->approvalRequest->client;

        return [
            'title'   => "Client {$this->response->response} an approval",
            'message' => "{$client->client_name} responded \"{$this->response->response}\" to \"{$this->approvalRequest->title}\".",
            'url'     => route('clients.show', $client),
        ];
    }
}
