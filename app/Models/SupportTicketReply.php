<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketReply extends Model
{
    public const AUTHOR_STAFF   = 'staff';
    public const AUTHOR_PORTAL  = 'client_portal';

    protected $fillable = [
        'support_ticket_id', 'author_type', 'author_user_id', 'author_portal_user_id', 'message',
        'original_name', 'stored_name', 'disk', 'path', 'mime_type', 'file_size', 'is_internal_note',
    ];

    protected function casts(): array
    {
        return ['is_internal_note' => 'boolean'];
    }

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function portalAuthor()
    {
        return $this->belongsTo(ClientPortalUser::class, 'author_portal_user_id');
    }

    public function scopeClientVisible($query)
    {
        return $query->where('is_internal_note', false);
    }
}
