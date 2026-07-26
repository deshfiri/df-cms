<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesPerformanceBoard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTarget extends Model
{
    use InvalidatesPerformanceBoard;

    protected $fillable = ['user_id', 'period', 'target_amount', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['target_amount' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
