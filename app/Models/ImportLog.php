<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    protected $fillable = [
        'user_id', 'file_name', 'file_path', 'total_rows', 'success_rows',
        'updated_rows', 'skipped_rows', 'failed_rows', 'duplicate_rows',
        'status', 'errors', 'validation_errors', 'mapping',
        'import_duration_seconds', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'errors'            => 'array',
            'validation_errors' => 'array',
            'mapping'           => 'array',
            'started_at'        => 'datetime',
            'completed_at'      => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
