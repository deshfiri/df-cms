<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BugReport extends Model
{
    use SoftDeletes;

    public const STATUS_OPEN     = 'Open';
    public const STATUS_RESOLVED = 'Resolved';
    public const STATUS_CLOSED   = 'Closed';

    public static array $statuses  = [self::STATUS_OPEN, self::STATUS_RESOLVED, self::STATUS_CLOSED];
    public static array $severities = ['Low', 'Medium', 'High', 'Critical'];

    protected $fillable = [
        'subject', 'message', 'severity', 'page_url', 'reported_by',
        'status', 'response_note', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function reportedBy()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
