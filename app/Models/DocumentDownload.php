<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentDownload extends Model
{
    public $timestamps = false;

    protected $fillable = ['document_id', 'user_id', 'ip_address', 'user_agent', 'downloaded_at'];

    protected function casts(): array
    {
        return ['downloaded_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ClientDocument::class, 'document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

// end

use Illuminate\Database\Eloquent\Model;

class DocumentDownload extends Model
{
    //
}
