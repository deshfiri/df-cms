<?php

namespace App\Services\Portal;

use App\Models\ClientApprovalRequest;
use App\Models\ClientApprovalResponse;
use App\Models\ClientPortalUser;
use App\Notifications\ApprovalResponseReceived;
use App\Services\PortalActivityLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PortalApprovalService
{
    public function __construct(
        private readonly PortalActivityLogService $activityLog,
    ) {}

    public function respond(
        ClientApprovalRequest $approvalRequest,
        ClientPortalUser $portalUser,
        string $response,
        ?string $comment,
        ?UploadedFile $file,
    ): ClientApprovalResponse {
        if ($response === ClientApprovalResponse::RESPONSE_REVISION_REQUESTED && empty($comment)) {
            throw ValidationException::withMessages(['comment' => 'A comment is required when requesting a revision.']);
        }

        if ($response === ClientApprovalResponse::RESPONSE_REJECTED && !$approvalRequest->allow_reject) {
            throw ValidationException::withMessages(['response' => 'Rejection is not allowed for this approval.']);
        }

        return DB::transaction(function () use ($approvalRequest, $portalUser, $response, $comment, $file) {
            $fileData = [];
            if ($file) {
                $ext = strtolower($file->getClientOriginalExtension());
                $storedName = Str::uuid() . '.' . $ext;
                $path = $file->storeAs('portal/approvals/' . $approvalRequest->client_id, $storedName, 'local');

                $fileData = [
                    'original_name' => $file->getClientOriginalName(),
                    'stored_name'   => $storedName,
                    'disk'          => 'local',
                    'path'          => $path,
                    'mime_type'     => $file->getMimeType() ?? 'application/octet-stream',
                    'file_size'     => $file->getSize(),
                ];
            }

            // Never overwrite previous history — every response is its own row.
            $approvalResponse = ClientApprovalResponse::create(array_merge([
                'client_approval_request_id' => $approvalRequest->id,
                'responded_by'               => $portalUser->id,
                'response'                   => $response,
                'comment'                    => $comment,
                'version'                    => $approvalRequest->version,
            ], $fileData));

            $newStatus = match ($response) {
                ClientApprovalResponse::RESPONSE_APPROVED           => ClientApprovalRequest::STATUS_APPROVED,
                ClientApprovalResponse::RESPONSE_REVISION_REQUESTED => ClientApprovalRequest::STATUS_REVISION_REQUESTED,
                ClientApprovalResponse::RESPONSE_REJECTED           => ClientApprovalRequest::STATUS_REJECTED,
            };
            $approvalRequest->update(['status' => $newStatus]);

            $this->activityLog->log(
                $portalUser,
                'Approval',
                $response === ClientApprovalResponse::RESPONSE_REVISION_REQUESTED ? 'Revision Requested' : $response,
                ClientApprovalRequest::class,
                $approvalRequest->id,
            );

            $requester = $approvalRequest->requestedBy;
            if ($requester) {
                Notification::send($requester, new ApprovalResponseReceived($approvalRequest, $approvalResponse));
            }

            return $approvalResponse;
        });
    }
}
