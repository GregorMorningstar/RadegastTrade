<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new task. Who may add it and to whom it may be assigned is decided by TaskService.
 */
class StoreTaskRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_milestone' => ['nullable', 'boolean'],
            'all_day' => ['nullable', 'boolean'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'depends_on_id' => ['nullable', 'integer'],
            // set by the backend, never by the form
            'organization_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'assignments' => ['required', 'array', 'min:1', 'max:200'],
            'assignments.*.type' => ['required', Rule::in(['organization', 'headquarters', 'branch', 'user'])],
            'assignments.*.unit_type' => ['nullable', Rule::in(['shops', 'warehouses'])],
            'assignments.*.id' => ['nullable', 'integer'],
        ];
    }

    public function attributes(): array
    {
        return ['title' => 'tytuł', 'starts_at' => 'początek', 'ends_at' => 'koniec', 'assignments' => 'adresaci', 'progress' => 'postęp'];
    }
}
