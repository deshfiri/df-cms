<?php

namespace App\Http\Requests\Task;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Oversight on any task, or 'manage tasks' on one you created — TaskPolicy::update.
        return $this->user()->can('update', $this->route('task'));
    }

    public function rules(): array
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'client_ids'       => ['nullable', 'array'],
            'client_ids.*'     => ['exists:clients,id'],
            'assignee_ids'     => ['nullable', 'array'],
            'assignee_ids.*'   => ['exists:users,id'],
            'priority'         => ['required', Rule::in(Task::$priorities)],
            'status'           => ['required', Rule::in(Task::$statuses)],
            'type'             => ['required', Rule::in(Task::$types)],
            'start_date'       => ['nullable', 'date'],
            'due_date'         => ['nullable', 'date'],
            'due_at'           => ['nullable', 'date'],
            'reminder_at'      => ['nullable', 'date'],
            // See StoreTaskRequest: the editor's minutes/hours/days picker
            // always converts to hours before it gets here.
            'estimated_hours'  => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'actual_hours'     => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'label_ids'        => ['nullable', 'array'],
            'label_ids.*'      => ['exists:labels,id'],
        ];
    }
}
