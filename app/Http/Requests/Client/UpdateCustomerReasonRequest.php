<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The inline Customer Reason edit on the clients list. The edit form reuses
 * customerReasonRules(), so both entry points accept exactly the same input.
 */
class UpdateCustomerReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('client'));
    }

    public static function customerReasonRules(): array
    {
        return ['nullable', 'string', 'max:1000'];
    }

    public function rules(): array
    {
        return ['customer_reason' => self::customerReasonRules()];
    }
}
