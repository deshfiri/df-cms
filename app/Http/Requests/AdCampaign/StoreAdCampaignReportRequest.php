<?php

namespace App\Http\Requests\AdCampaign;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdCampaignReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report_date' => ['required', 'date'],
            'spend'       => ['required', 'numeric', 'min:0'],
            'sales'       => ['required', 'numeric', 'min:0'],
            'leads'       => ['required', 'integer', 'min:0'],
            'orders'      => ['required', 'integer', 'min:0'],
            'remarks'     => ['nullable', 'string'],
        ];
    }
}
