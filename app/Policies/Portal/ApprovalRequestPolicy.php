<?php

namespace App\Policies\Portal;

use App\Models\ClientApprovalRequest;
use App\Models\ClientPortalUser;

class ApprovalRequestPolicy
{
    public function view(ClientPortalUser $portalUser, ClientApprovalRequest $approvalRequest): bool
    {
        return $approvalRequest->client_id === $portalUser->client_id;
    }

    public function respond(ClientPortalUser $portalUser, ClientApprovalRequest $approvalRequest): bool
    {
        return $this->view($portalUser, $approvalRequest)
            && in_array($approvalRequest->status, [
                ClientApprovalRequest::STATUS_PENDING,
                ClientApprovalRequest::STATUS_REVISION_REQUESTED,
            ], true);
    }

    public function reject(ClientPortalUser $portalUser, ClientApprovalRequest $approvalRequest): bool
    {
        return $this->respond($portalUser, $approvalRequest) && $approvalRequest->allow_reject;
    }
}
