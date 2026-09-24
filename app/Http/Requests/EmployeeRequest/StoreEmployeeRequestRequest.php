<?php

namespace App\Http\Requests\EmployeeRequest;

use App\Models\EmployeeRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', EmployeeRequest::class);
    }

    public function rules(): array
    {
        return [
            'subject'          => ['required', 'string', 'max:255'],
            'message'          => ['required', 'string', 'max:5000'],
            'client_id'        => ['nullable', 'exists:clients,id'],
            // Who this goes to — the only people who will see or can act on
            // it (besides whoever files it). No longer a broadcast to
            // everyone holding "manage requests".
            'recipient_ids'    => ['required', 'array', 'min:1'],
            'recipient_ids.*'  => ['distinct', 'exists:users,id', Rule::notIn([$this->user()->id])],
        ];
    }
}
