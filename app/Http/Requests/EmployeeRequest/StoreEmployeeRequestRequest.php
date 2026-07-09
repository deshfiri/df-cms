<?php

namespace App\Http\Requests\EmployeeRequest;

use App\Models\EmployeeRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', EmployeeRequest::class);
    }

    public function rules(): array
    {
        return [
            'subject'   => ['required', 'string', 'max:255'],
            'message'   => ['required', 'string', 'max:5000'],
            'client_id' => ['nullable', 'exists:clients,id'],
        ];
    }
}
