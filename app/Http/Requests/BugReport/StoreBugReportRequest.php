<?php

namespace App\Http\Requests\BugReport;

use App\Models\BugReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBugReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', BugReport::class);
    }

    public function rules(): array
    {
        return [
            'subject'  => ['required', 'string', 'max:255'],
            'message'  => ['required', 'string', 'max:5000'],
            'severity' => ['required', Rule::in(BugReport::$severities)],
            'page_url' => ['nullable', 'string', 'max:500'],
        ];
    }
}
