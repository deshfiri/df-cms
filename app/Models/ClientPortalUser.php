<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class ClientPortalUser extends Authenticatable
{
    use Notifiable, SoftDeletes;

    public const STATUS_ACTIVE    = 'Active';
    public const STATUS_SUSPENDED = 'Suspended';
    public const STATUS_DISABLED  = 'Disabled';
    public const STATUS_COMPLETED = 'Completed';

    public static array $statuses = [
        self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_DISABLED, self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'client_id', 'name', 'email', 'phone', 'password', 'is_primary', 'status', 'created_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_primary'        => 'boolean',
            'last_login_at'     => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notificationPreference()
    {
        return $this->hasOne(ClientPortalNotificationPreference::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
