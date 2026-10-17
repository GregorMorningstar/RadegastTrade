<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One table sent by a connector. There is no field for the organization or the unit: they come only from the API key
 * (a request naming them is refused, not trusted).
 */
class SyncDataRequest extends FormRequest
{
    public const MAX_ROWS = 10000;

    public function authorize(): bool
    {
        // the connector was let in by its API key (AuthenticateApiKey)
        return $this->attributes->has('api_key');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'table' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.]+$/'],
            'syncedAtUtc' => ['nullable', 'date'],
            'rowCount' => ['nullable', 'integer', 'min:0'],
            'rows' => ['present', 'array', 'max:'.self::MAX_ROWS],
            'rows.*' => ['array', 'max:300'],

            // the unit and the organization are never taken from the data
            'organization_id' => ['prohibited'],
            'unit' => ['prohibited'],
            'placowka_id' => ['prohibited'],
        ];
    }
}
