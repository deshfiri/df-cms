<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = ['name', 'email', 'password', 'avatar', 'avatar_disk', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Where this user's profile picture can be fetched, or null when they have
     * none. Proxied like every other upload, so it is served with the same
     * permissions wherever the file happens to live.
     */
    public function avatarUrl(): ?string
    {
        return filled($this->avatar) ? route('users.avatar', $this) : null;
    }

    /** The letters shown when there is no picture. */
    public function initials(): string
    {
        return mb_strtoupper(mb_substr(trim($this->name) ?: '?', 0, 1));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    public function clients()
    {
        return $this->hasMany(Client::class, 'assigned_to');
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function notes()
    {
        return $this->hasMany(ClientNote::class);
    }

    public function salesTargets()
    {
        return $this->hasMany(SalesTarget::class);
    }

    public function capacity()
    {
        return $this->hasOne(EmployeeCapacity::class);
    }

    public function satisfactionRatings()
    {
        return $this->hasMany(ClientSatisfactionRating::class, 'employee_id');
    }

    public function taskRevisionsRequested()
    {
        return $this->hasMany(TaskRevision::class, 'requested_by');
    }
}
