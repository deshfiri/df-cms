<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt at synchronising a brand's platform data.
 *
 * Opened as `running` before the work starts, so a job that dies mid-flight
 * leaves evidence rather than silence.
 */
class SyncLog extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const TYPE_FULL   = 'full';
    public const TYPE_MANUAL = 'manual';

    protected $fillable = [
        'brand_id', 'brand_integration_id', 'platform', 'sync_type', 'status',
        'started_at', 'completed_at', 'records_processed', 'error_message',
        'metadata', 'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
            'metadata'     => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(BrandIntegration::class, 'brand_integration_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function durationSeconds(): ?int
    {
        if (!$this->completed_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }

    public function scopeRunning($query)
    {
        return $query->where('status', self::STATUS_RUNNING);
    }
}
