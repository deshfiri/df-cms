<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientProjectUpdate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'stage_id', 'department', 'title', 'description', 'progress_percent',
        'next_action', 'expected_completion_date', 'video_url', 'external_link',
        'original_name', 'stored_name', 'disk', 'path', 'mime_type', 'file_size',
        'is_client_visible', 'visible_to_client_at', 'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'is_client_visible'        => 'boolean',
            'visible_to_client_at'     => 'datetime',
            'expected_completion_date' => 'date',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'stage_id');
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function scopeVisible($query)
    {
        return $query->where('is_client_visible', true);
    }

    public function getHasAttachmentAttribute(): bool
    {
        return !empty($this->path);
    }
}
