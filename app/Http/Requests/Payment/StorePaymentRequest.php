<?php

namespace App\Http\Requests\Payment;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'             => ['nullable', 'numeric', 'min:0'],
            'payment_date'       => ['nullable', 'date'],
            'payment_method'     => ['nullable', 'string', 'max:100'],
            'transaction_number' => ['nullable', 'string', 'max:100'],
            'status'             => ['required', Rule::in(Payment::$statuses)],
            'remarks'            => ['nullable', 'string'],
        ];
    }
}
