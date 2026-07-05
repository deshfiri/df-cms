<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    public static array $types = ['review', 'report'];

    protected $fillable = [
        'type', 'subject_user_id', 'subject_department',
        'title', 'message', 'is_anonymous', 'posted_by', 'poster_token',
    ];

    protected $hidden = ['poster_token'];

    protected function casts(): array
    {
        return ['is_anonymous' => 'boolean'];
    }

    public function subjectUser()
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
