<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkflowStage extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code', 'department', 'requires_approval', 'sort_order', 'status'];

    protected function casts(): array
    {
        return [
            'status'            => 'boolean',
            'requires_approval' => 'boolean',
        ];
    }

    public function clientProgress()
    {
        return $this->hasMany(ClientStageProgress::class, 'stage_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true)->orderBy('sort_order');
    }
}
