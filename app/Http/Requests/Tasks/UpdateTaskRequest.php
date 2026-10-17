<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changes of a task: only the given fields are changed. Who may change which of them is decided by TaskService.
 */
class UpdateTaskRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_milestone' => ['sometimes', 'boolean'],
            'all_day' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'depends_on_id' => ['sometimes', 'nullable', 'integer'],
            // set by the backend, never by the form
            'organization_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'assignments' => ['sometimes', 'array', 'min:1', 'max:200'],
            'assignments.*.type' => ['required_with:assignments', Rule::in(['organization', 'headquarters', 'branch', 'user'])],
            'assignments.*.unit_type' => ['nullable', Rule::in(['shops', 'warehouses'])],
            'assignments.*.id' => ['nullable', 'integer'],
        ];
    }

    public function attributes(): array
    {
        return ['title' => 'tytuł', 'starts_at' => 'początek', 'ends_at' => 'koniec', 'assignments' => 'adresaci', 'progress' => 'postęp'];
    }
}
