<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdCampaignAssignment extends Model
{
    protected $fillable = [
        'ad_campaign_id', 'previous_assignee_id', 'new_assignee_id', 'assigned_by', 'note',
    ];

    public function campaign()
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function previousAssignee()
    {
        return $this->belongsTo(User::class, 'previous_assignee_id');
    }

    public function newAssignee()
    {
        return $this->belongsTo(User::class, 'new_assignee_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
