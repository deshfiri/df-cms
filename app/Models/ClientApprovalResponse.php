<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientApprovalResponse extends Model
{
    public const RESPONSE_APPROVED           = 'Approved';
    public const RESPONSE_REVISION_REQUESTED = 'Revision Requested';
    public const RESPONSE_REJECTED           = 'Rejected';

    public static array $responses = [
        self::RESPONSE_APPROVED, self::RESPONSE_REVISION_REQUESTED, self::RESPONSE_REJECTED,
    ];

    protected $fillable = [
        'client_approval_request_id', 'responded_by', 'response', 'comment', 'version',
        'original_name', 'stored_name', 'disk', 'path', 'mime_type', 'file_size',
    ];

    public function approvalRequest()
    {
        return $this->belongsTo(ClientApprovalRequest::class, 'client_approval_request_id');
    }

    public function respondedBy()
    {
        return $this->belongsTo(ClientPortalUser::class, 'responded_by');
    }
}
