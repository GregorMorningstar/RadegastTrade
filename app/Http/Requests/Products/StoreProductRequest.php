<?php

namespace App\Http\Requests\Products;

use App\Support\Barcode;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'barcode' => [
                'required',
                'digits:13',
                'unique:products,barcode',
                fn ($attribute, $value, $fail) => Barcode::isValidEan13((string) $value)
                    ?: $fail('Niepoprawna cyfra kontrolna EAN-13.'),
                // user, organization and headquarters prefixes are reserved for the system
                fn ($attribute, $value, $fail) => ! Barcode::usesEntityPrefix((string) $value)
                    ?: $fail('Ten prefiks kodu jest zarezerwowany dla kart i placówek systemu.'),
            ],
        ];
    }
}
