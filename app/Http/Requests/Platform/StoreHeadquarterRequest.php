<?php

namespace App\Http\Requests\Platform;

use App\Rules\RegisteredNip;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreHeadquarterRequest extends FormRequest
{
    /**
     * Uniqueness of the name and NIP (except the organization's NIP) is checked by HeadquarterService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'NIP' => ['nullable', 'digits:10', new RegisteredNip],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'street' => ['nullable', 'string', 'max:255'],
            'building_number' => ['nullable', 'string', 'max:20'],
            'apartment_number' => ['nullable', 'string', 'max:20'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
        ];
    }

    /**
     * Empty fields are stored as NULL; the country defaults to PL.
     */
    protected function prepareForValidation(): void
    {
        $data = collect($this->all())
            ->map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value)
            ->all();

        $data['country'] = isset($data['country']) ? strtoupper($data['country']) : 'PL';

        $this->merge($data);
    }
}
