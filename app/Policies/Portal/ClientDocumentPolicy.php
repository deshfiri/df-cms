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

    public function upload(ClientPortalUser $portalUser, DocumentType $documentType): bool
    {
        return $documentType->is_client_submittable;
    }
}
