<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductUpdate extends Model
{
    protected $fillable = ['client_id', 'status', 'received_date', 'remarks', 'created_by'];

    protected function casts(): array
    {
        return ['received_date' => 'date'];
    }

    public static array $statuses = [
        'Processing', 'Ad Running', 'Product Hold', 'Product Research',
        'Product Source', 'Communication Gap', 'Financial Issue',
        'Need Boss Approval', 'Waiting For Product', 'Product Received',
        'Selected', 'Completed',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
