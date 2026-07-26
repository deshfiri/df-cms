<?php

namespace App\Policies\Portal;

use App\Models\ClientPortalUser;
use App\Models\Invoice;

class InvoicePolicy
{
    public function view(ClientPortalUser $portalUser, Invoice $invoice): bool
    {
        return $invoice->client_id === $portalUser->client_id;
    }
}
