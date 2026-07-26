<?php

namespace App\Policies\Portal;

use App\Models\ClientActionRequest;
use App\Models\ClientPortalUser;

class ActionRequestPolicy
{
    public function view(ClientPortalUser $portalUser, ClientActionRequest $actionRequest): bool
    {
        return $actionRequest->client_id === $portalUser->client_id;
    }

    public function submit(ClientPortalUser $portalUser, ClientActionRequest $actionRequest): bool
    {
        return $this->view($portalUser, $actionRequest)
            && in_array($actionRequest->status, [
                ClientActionRequest::STATUS_PENDING,
                ClientActionRequest::STATUS_NEED_REVISION,
            ], true);
    }
}
