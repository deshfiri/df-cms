<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientPortalNotificationPreference extends Model
{
    protected $fillable = [
        'client_portal_user_id', 'email_enabled', 'notify_journey_updates', 'notify_project_updates',
        'notify_action_requests', 'notify_approval_requests', 'notify_documents', 'notify_payments', 'notify_support',
    ];

    protected function casts(): array
    {
        return [
            'email_enabled'             => 'boolean',
            'notify_journey_updates'    => 'boolean',
            'notify_project_updates'    => 'boolean',
            'notify_action_requests'    => 'boolean',
            'notify_approval_requests'  => 'boolean',
            'notify_documents'          => 'boolean',
            'notify_payments'           => 'boolean',
            'notify_support'            => 'boolean',
        ];
    }

    public function portalUser()
    {
        return $this->belongsTo(ClientPortalUser::class, 'client_portal_user_id');
    }

    public static function forUser(ClientPortalUser $user): self
    {
        return static::firstOrCreate(['client_portal_user_id' => $user->id]);
    }
}
