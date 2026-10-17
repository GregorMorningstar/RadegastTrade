<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters of the product list: a category, a search text, the sort order and the page. Who may look is decided by the
 * service (policy), and which category exists by the organization of the person.
 */
class ProductIndexRequest extends FormRequest
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
            'category' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:name,name_desc,newest'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
