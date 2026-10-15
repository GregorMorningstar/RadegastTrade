<?php

namespace App\Http\Requests\Platform;

use App\Rules\RegisteredNip;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // the organization name is provided by the invited person; the email comes from the invitation (the controller sets it)
            'name' => ['required', 'string', 'max:255'],
            // NIP must be unique across organizations (branches may share it with their organization)
            'NIP' => [
                'nullable',
                'digits:10',
                new RegisteredNip,
                'unique:organizations,NIP',
            ],
            'REGON' => ['nullable', 'digits:9', 'unique:organizations,REGON'],
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
     * Empty form fields are stored as NULL (unique NIP/REGON must not be an empty string).
     */
    protected function prepareForValidation(): void
    {
        $this->merge(
            collect($this->all())
                ->map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value)
                ->all()
        );
    }
}
