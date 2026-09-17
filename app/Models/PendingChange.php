<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingChange extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    /** Made directly by someone allowed to approve — recorded, never waited. */
    public const STATUS_APPLIED  = 'applied';

    /** new_values marker for a request to delete the record rather than edit it. */
    public const ACTION_KEY    = '_action';
    public const ACTION_DELETE = 'delete';

    protected $fillable = [
        'model_type', 'model_id', 'old_values', 'new_values', 'reason',
        'requested_by', 'status', 'reviewed_by', 'reviewed_at', 'applied_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'old_values'  => 'array',
            'new_values'  => 'array',
            'reviewed_at' => 'datetime',
            'applied_at'  => 'datetime',
        ];
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The live model this change targets, or null if it's since been deleted.
     */
    public function subject(): ?Model
    {
        if (!class_exists($this->model_type)) {
            return null;
        }

        return $this->model_type::find($this->model_id);
    }

    public function isDeletion(): bool
    {
        return ($this->new_values[self::ACTION_KEY] ?? null) === self::ACTION_DELETE;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeFor($query, string $modelClass, int $modelId)
    {
        return $query->where('model_type', $modelClass)->where('model_id', $modelId);
    }
}
