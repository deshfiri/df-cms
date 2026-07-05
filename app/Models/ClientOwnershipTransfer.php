<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientOwnershipTransfer extends Model
{
    protected $fillable = [
        'client_id', 'previous_owner_id', 'new_owner_id', 'transferred_by', 'note',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function previousOwner()
    {
        return $this->belongsTo(User::class, 'previous_owner_id');
    }

    public function newOwner()
    {
        return $this->belongsTo(User::class, 'new_owner_id');
    }

    public function transferredBy()
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
