<?php

namespace App\Http\Requests\Client;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Client::class);
    }

    public function rules(): array
    {
        return [
            'dfid_number'   => ['nullable', 'string', 'max:50', Rule::unique('clients', 'dfid_number')],
            'client_name'   => ['required', 'string', 'max:255'],
            'brand_name'    => ['required', 'string', 'max:255'],
            'website'       => ['nullable', 'url', 'max:255'],
            'page_link'     => ['nullable', 'url', 'max:255'],
            'designs_link'  => ['nullable', 'url', 'max:255'],
            'category_id'   => ['required', 'exists:categories,id'],
            'joining_date'  => ['nullable', 'date'],
            'assigned_to'   => ['nullable', 'exists:users,id'],
            'client_status' => ['required', Rule::in(Client::$statuses)],
            'remarks'       => ['nullable', 'string'],
            'doc_status'    => ['nullable', 'string', 'max:50'],
        ];
    }
}
