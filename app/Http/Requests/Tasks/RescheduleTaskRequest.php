<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Moving a task in time (dragging in the calendar or on the Gantt chart).
 */
class RescheduleTaskRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'all_day' => ['nullable', 'boolean'],
        ];
    }
}
