<?php

namespace App\Http\Requests\Platform;

use App\Services\NipService;
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
            // nazwę organizacji podaje zapraszana osoba; e-mail pochodzi z zaproszenia (kontroler go ustawia)
            'name' => ['required', 'string', 'max:255'],
            // NIP musi być unikalny w skali organizacji (placówki mogą go współdzielić z organizacją)
            'NIP' => [
                'nullable',
                'digits:10',
                fn ($attribute, $value, $fail) => app(NipService::class)->validateNip((string) $value)
                    ?: $fail('Nieprawidłowy numer NIP (błędna suma kontrolna).'),
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
     * Puste pola formularza zapisujemy jako NULL (unikalne NIP/REGON nie mogą być pustym tekstem).
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
