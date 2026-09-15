<?php

namespace App\Http\Requests\Payment;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A payment is one of three things, told apart by what is sent:
 *
 *  - `invoice_id`   — money received against an existing charge;
 *  - `charge_total` — open a new charge for a category and take this against it
 *                     ("Social Media Ads is ৳20,000; ৳10,000 paid today");
 *  - neither        — a standalone payment with its own Paid / Partial / Unpaid.
 *
 * Whether the amount actually fits the charge is decided in PaymentService,
 * under a row lock, not here.
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return self::baseRules($this->isMethod('post'));
    }

    /** Shared with PaymentController::storeAny, which adds the client. */
    public static function baseRules(bool $creating = true): array
    {
        $category = Rule::exists('payment_categories', 'id');
        if ($creating) {
            // An archived category stays on old records but takes no new ones.
            $category = $category->where('is_active', true);
        }

        return [
            'payment_category_id' => ['nullable', 'integer', $category],
            'invoice_id'          => ['nullable', 'integer', Rule::exists('invoices', 'id')->whereNull('deleted_at')],

            'charge_total'        => $creating
                ? ['nullable', 'numeric', 'min:0.01', 'max:9999999999', 'prohibits:invoice_id', 'required_with:charge_title']
                : ['prohibited'],
            'charge_title'        => ['nullable', 'string', 'max:200'],
            'charge_due_date'     => ['nullable', 'date'],

            'amount'              => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'required_with:invoice_id,charge_total'],
            'payment_date'        => ['nullable', 'date'],
            'payment_method'      => ['nullable', 'string', 'max:100'],
            'transaction_number'  => ['nullable', 'string', 'max:100'],
            // Against a charge it is always Paid, so only a standalone payment must say.
            'status'              => $creating
                ? ['nullable', 'required_without_all:invoice_id,charge_total', Rule::in(Payment::$statuses)]
                : ['sometimes', 'required', Rule::in(Payment::$statuses)],
            'remarks'             => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return self::baseMessages();
    }

    public static function baseMessages(): array
    {
        return [
            'payment_category_id.exists' => 'Pick an active payment category.',
            'charge_total.prohibits'     => 'Choose an existing charge or open a new one, not both.',
            'charge_total.required_with' => 'Enter the total for the new charge.',
            'amount.required_with'       => 'Enter the amount received.',
            'status.required_without_all' => 'Choose a status for this payment.',
        ];
    }
}
