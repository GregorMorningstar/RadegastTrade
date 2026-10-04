<?php

namespace App\Http\Requests\Products;

use App\Models\User;
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
                // prefiks kart użytkowników jest zarezerwowany
                'not_regex:/^'.User::BARCODE_PREFIX.'/',
                fn ($attribute, $value, $fail) => Barcode::isValidEan13((string) $value)
                    ?: $fail('Niepoprawna cyfra kontrolna EAN-13.'),
            ],
        ];
    }
}
