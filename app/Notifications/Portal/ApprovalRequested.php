<?php

namespace App\Notifications\Portal;

use App\Models\ClientApprovalRequest;
use Illuminate\Notifications\Notification;

class ApprovalRequested extends Notification
{
    public function __construct(
        private readonly ClientApprovalRequest $approvalRequest,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'   => 'Approval requested',
            'message' => "\"{$this->approvalRequest->title}\" (v{$this->approvalRequest->version}) needs your review.",
            'url'     => route('portal.approvals.show', $this->approvalRequest),
        ];
    }
}
