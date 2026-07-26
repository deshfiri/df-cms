<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalActivityLog extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'client_portal_user_id', 'client_id', 'module', 'action',
        'related_type', 'related_id', 'ip_address', 'user_agent',
    ];

    public function portalUser()
    {
        return $this->belongsTo(ClientPortalUser::class, 'client_portal_user_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
