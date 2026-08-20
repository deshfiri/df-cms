<?php

namespace App\Http\Requests\Task;

use App\Models\Task;
use App\Rules\AssignableUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 'manage tasks', or a stage worker delegating to their next stage.
        return $this->user()->can('create', Task::class);
    }

    public function rules(): array
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            // Optional: internal work — delegating something to a colleague —
            // has no client to attach it to.
            'client_id'        => ['nullable', 'exists:clients,id'],
            'assigned_to'      => ['nullable', 'exists:users,id', new AssignableUser($this->user())],
            'priority'         => ['required', Rule::in(Task::$priorities)],
            'status'           => ['required', Rule::in(Task::$statuses)],
            'type'             => ['required', Rule::in(Task::$types)],
            'start_date'       => ['nullable', 'date'],
            'due_date'         => ['nullable', 'date'],
            'reminder_at'      => ['nullable', 'date'],
            'estimated_hours'  => ['nullable', 'numeric', 'min:0'],
            'label_ids'        => ['nullable', 'array'],
            'label_ids.*'      => ['exists:labels,id'],
        ];
    }
}
