<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeRequestForward extends Model
{
    protected $fillable = ['employee_request_id', 'from_user_id', 'to_user_id', 'note'];

    public function employeeRequest()
    {
        return $this->belongsTo(EmployeeRequest::class);
    }

    public function fromUser()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser()
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
