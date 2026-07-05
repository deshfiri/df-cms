<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportLog extends Model
{
    protected $fillable = [
        'user_id', 'export_type', 'scope', 'record_count', 'file_path', 'filters',
    ];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
