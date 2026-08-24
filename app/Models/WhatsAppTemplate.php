<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A local mirror of a Meta-approved template.
 *
 * Never a source of truth: `status` reflects only what the last sync reported,
 * so the send path re-checks {@see isApproved()} at the moment of use rather
 * than trusting a row that may be hours stale.
 */
class WhatsAppTemplate extends Model
{
    public const STATUS_APPROVED = 'APPROVED';

    /** Explicit: the convention would look for `whats_app_templates`. */
    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'whatsapp_account_id', 'template_id', 'name', 'language',
        'category', 'status', 'components', 'metadata', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'metadata'   => 'array',
            'synced_at'  => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return strtoupper((string) $this->status) === self::STATUS_APPROVED;
    }

    /**
     * How many {{n}} placeholders the body carries.
     *
     * The send path uses this to reject a template call with the wrong number of
     * parameters before it reaches Meta, where the error would be opaque.
     */
    public function bodyParameterCount(): int
    {
        foreach ($this->components ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') !== 'BODY') {
                continue;
            }

            preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $component['text'] ?? '', $matches);

            return $matches[1] ? max(array_map('intval', $matches[1])) : 0;
        }

        return 0;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
}
