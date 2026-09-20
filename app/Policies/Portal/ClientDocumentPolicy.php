<?php

namespace App\Policies\Portal;

use App\Models\ClientDocument;
use App\Models\ClientPortalUser;
use App\Models\DocumentType;

class ClientDocumentPolicy
{
    public function view(ClientPortalUser $portalUser, ClientDocument $document): bool
    {
        return $document->client_id === $portalUser->client_id && $document->is_client_visible;
    }

    /**
     * A type has to be open to clients *and* still switched on: retiring a type
     * in Settings → Document Types has to close the portal's form too, not just
     * drop it from the list.
     */
    public function upload(ClientPortalUser $portalUser, DocumentType $documentType): bool
    {
        return $documentType->is_client_submittable && $documentType->is_active;
    }
}
