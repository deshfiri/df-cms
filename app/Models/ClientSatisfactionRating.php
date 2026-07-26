<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesPerformanceBoard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientSatisfactionRating extends Model
{
    use InvalidatesPerformanceBoard;

    public const SOURCE_SUPPORT_TICKET   = 'SupportTicket';
    public const SOURCE_WORKFLOW_DEPARTMENT = 'WorkflowDepartment';

    protected $fillable = [
        'client_id', 'employee_id', 'source_type', 'source_id', 'department',
        'rated_by', 'rating', 'comment',
        'is_excluded', 'excluded_by', 'excluded_reason', 'excluded_at',
    ];

    protected function casts(): array
    {
        return [
            'is_excluded' => 'boolean',
            'excluded_at' => 'datetime',
            'rating'      => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function ratedBy(): BelongsTo
    {
        return $this->belongsTo(ClientPortalUser::class, 'rated_by');
    }

    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }

    public function scopeIncluded($query)
    {
        return $query->where('is_excluded', false);
    }
}
