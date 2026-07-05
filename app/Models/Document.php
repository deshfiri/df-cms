<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'client_id', 'document_type', 'title', 'file_path',
        'original_name', 'mime_type', 'file_size', 'uploaded_by',
    ];

    public static array $types = [
        'Agreement', 'NID', 'Invoice', 'Brand Logo', 'Brand Files', 'Others',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size ?? 0;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }
}
