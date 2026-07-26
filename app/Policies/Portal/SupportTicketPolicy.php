<?php

namespace App\Policies\Portal;

use App\Models\ClientPortalUser;
use App\Models\SupportTicket;

class SupportTicketPolicy
{
    public function view(ClientPortalUser $portalUser, SupportTicket $ticket): bool
    {
        return $ticket->client_id === $portalUser->client_id;
    }

    public function reply(ClientPortalUser $portalUser, SupportTicket $ticket): bool
    {
        return $this->view($portalUser, $ticket) && !$ticket->isClosed();
    }
}
