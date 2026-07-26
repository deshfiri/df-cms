<?php

namespace App\Http\Requests\AdCampaign;

use App\Models\AdCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:191'],
            'brand_id'    => ['nullable', Rule::exists('brands', 'id')->where(fn ($q) => $q->where('client_id', $this->route('client')?->id))],
            'platform'    => ['nullable', Rule::in(AdCampaign::$platforms)],
            'budget'      => ['nullable', 'numeric', 'min:0'],
            'status'      => ['required', Rule::in(AdCampaign::$statuses)],
            'start_date'  => ['nullable', 'date'],
            'end_date'    => ['nullable', 'date', 'after_or_equal:start_date'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'remarks'     => ['nullable', 'string'],
        ];
    }
}
