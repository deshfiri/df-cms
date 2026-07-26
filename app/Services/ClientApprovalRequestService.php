<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientApprovalRequest;
use App\Models\User;
use App\Notifications\Portal\ApprovalRequested;
use App\Services\Portal\NotifiesPortalUsers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientApprovalRequestService
{
    use NotifiesPortalUsers;

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(Client $client, array $data, ?UploadedFile $file, User $actor): ClientApprovalRequest
    {
        return DB::transaction(function () use ($client, $data, $file, $actor) {
            $fileData = [];
            if ($file) {
                $ext = strtolower($file->getClientOriginalExtension());
                $storedName = Str::uuid() . '.' . $ext;
                $path = $file->storeAs('portal/approvals/' . $client->id, $storedName, 'local');
                $fileData = [
                    'original_name' => $file->getClientOriginalName(),
                    'stored_name'   => $storedName,
                    'disk'          => 'local',
                    'path'          => $path,
                    'mime_type'     => $file->getMimeType() ?? 'application/octet-stream',
                    'file_size'     => $file->getSize(),
                ];
            }

            $approvalRequest = ClientApprovalRequest::create(array_merge($data, $fileData, [
                'client_id'    => $client->id,
                'requested_by' => $actor->id,
                'status'       => ClientApprovalRequest::STATUS_PENDING,
            ]));

            $this->activityLog->log('Approval', 'Requested', $client->id, null, ['title' => $approvalRequest->title]);
            $this->notifyPortalUsers($client, new ApprovalRequested($approvalRequest));

            return $approvalRequest;
        });
    }

    /**
     * Re-versions an approval after client-requested revisions: bumps the
     * version, replaces the preview file, and resets to Pending — the old
     * responses stay in client_approval_responses untouched (history preserved).
     */
    public function reversion(ClientApprovalRequest $approvalRequest, ?UploadedFile $file, ?string $description): ClientApprovalRequest
    {
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

        $approvalRequest->update(array_merge($fileData, [
            'version'     => $approvalRequest->version + 1,
            'status'      => ClientApprovalRequest::STATUS_PENDING,
            'description' => $description ?? $approvalRequest->description,
        ]));

        $this->activityLog->log('Approval', 'Re-submitted (v' . $approvalRequest->version . ')', $approvalRequest->client_id);
        $this->notifyPortalUsers($approvalRequest->client, new ApprovalRequested($approvalRequest));

        return $approvalRequest;
    }
}
