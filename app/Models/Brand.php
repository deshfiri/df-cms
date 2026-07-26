<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use SoftDeletes;

    protected $fillable = ['client_id', 'name', 'remarks', 'created_by', 'updated_by'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function adCampaigns()
    {
        return $this->hasMany(AdCampaign::class)->latest();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
