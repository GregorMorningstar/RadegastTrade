<?php

namespace App\Http\Requests\Platform;

use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Invitation of a headquarters manager or branch leader: email and phone number.
 */
class StoreMemberInvitationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $email = (string) $this->input('email');

        return [
            // in production we also check that the domain has mail records
            'email' => [
                'required', 'string', app()->isProduction() ? 'email:rfc,dns' : 'email:rfc', 'max:255',
                fn ($attribute, $value, $fail) => strtolower((string) $value) === strtolower((string) $this->user()?->email)
                    ? $fail('Nie możesz zaprosić samego siebie.')
                    : null,
            ],
            'phone' => [
                'required', 'string', 'max:20',
                fn ($attribute, $value, $fail) => PhoneNumber::isValid((string) $value)
                    ? null
                    : $fail('Podaj poprawny numer telefonu: 9 cyfr albo numer międzynarodowy zaczynający się od +.'),
                // the number must not belong to another account (the account with this email may already have it saved)
                fn ($attribute, $value, $fail) => User::where('phone', $value)->where('email', '!=', strtolower($email))->exists()
                    ? $fail('Ten numer telefonu jest już przypisany do innego konta.')
                    : null,
                // nor be waiting in an active invitation sent to a different address
                fn ($attribute, $value, $fail) => OrganizationInvitation::where('phone', $value)
                    ->where('email', '!=', strtolower($email))
                    ->where('is_active', true)
                    ->whereNull('accepted_at')
                    ->where('expires_at', '>', now())
                    ->exists()
                    ? $fail('Na ten numer wysłano już zaproszenie dla innego adresu e-mail.')
                    : null,
            ],
        ];
    }

    public function attributes(): array
    {
        return ['email' => 'adres e-mail', 'phone' => 'numer telefonu'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
            'phone' => PhoneNumber::normalize(is_string($this->input('phone')) ? $this->input('phone') : null),
        ]);
    }
}
