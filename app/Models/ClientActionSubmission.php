<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientActionSubmission extends Model
{
    protected $fillable = [
        'client_action_request_id', 'submitted_by', 'response_text',
        'original_name', 'stored_name', 'disk', 'path', 'mime_type', 'file_size',
    ];

    public function actionRequest()
    {
        return $this->belongsTo(ClientActionRequest::class, 'client_action_request_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(ClientPortalUser::class, 'submitted_by');
    }

    public function getHasAttachmentAttribute(): bool
    {
        return !empty($this->path);
    }
}
