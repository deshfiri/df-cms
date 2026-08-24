<?php

namespace App\Services\Portal;

use App\Models\ClientActionRequest;
use App\Models\ClientActionSubmission;
use App\Models\ClientPortalUser;
use App\Notifications\ActionRequestSubmitted;
use App\Services\PortalActivityLogService;
use App\Services\Storage\StorageSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PortalActionRequestService
{
    public function __construct(
        private readonly PortalActivityLogService $activityLog,
        private readonly StorageSettings $storage,
    ) {}

    public function submit(
        ClientActionRequest $actionRequest,
        ClientPortalUser $portalUser,
        ?string $responseText,
        ?UploadedFile $file,
    ): ClientActionSubmission {
        return DB::transaction(function () use ($actionRequest, $portalUser, $responseText, $file) {
            $fileData = [];
            if ($file) {
                $ext = strtolower($file->getClientOriginalExtension());
                $storedName = Str::uuid() . '.' . $ext;
                $disk = $this->storage->activeDisk();
                $path = $file->storeAs('portal/actions/' . $actionRequest->client_id, $storedName, $disk);

                $fileData = [
                    'original_name' => $file->getClientOriginalName(),
                    'stored_name'   => $storedName,
                    'disk'          => $disk,
                    'path'          => $path,
                    'mime_type'     => $file->getMimeType() ?? 'application/octet-stream',
                    'file_size'     => $file->getSize(),
                ];
            }

            $submission = ClientActionSubmission::create(array_merge([
                'client_action_request_id' => $actionRequest->id,
                'submitted_by'             => $portalUser->id,
                'response_text'            => $responseText,
            ], $fileData));

            $actionRequest->update(['status' => ClientActionRequest::STATUS_SUBMITTED]);

            $this->activityLog->log($portalUser, 'Pending Action', 'Submitted', ClientActionRequest::class, $actionRequest->id);

            $requester = $actionRequest->requestedBy;
            if ($requester) {
                Notification::send($requester, new ActionRequestSubmitted($actionRequest));
            }

            return $submission;
        });
    }
}
