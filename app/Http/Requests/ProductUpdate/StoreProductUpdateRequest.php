<?php

namespace App\Http\Requests\ProductUpdate;

use App\Models\ProductUpdate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'  => ['required', Rule::in(ProductUpdate::$statuses)],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
