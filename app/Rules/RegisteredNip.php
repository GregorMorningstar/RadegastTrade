<?php

namespace App\Rules;

use App\Services\Contracts\NipVerificationServiceInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A NIP typed during registration must be well formed, have a valid checksum and (when the registry check is
 * enabled) exist in the Ministry of Finance register. An empty value passes: combine with required if needed.
 */
class RegisteredNip implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $result = app(NipVerificationServiceInterface::class)->verify((string) $value);

        if (! $result['valid']) {
            $fail($result['message']);
        }
    }
}
