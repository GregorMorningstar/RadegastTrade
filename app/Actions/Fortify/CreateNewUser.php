<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\OrganizationInvitation;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly CreateTeam $createTeam)
    {
        //
    }

    /**
     * Validate and create a newly registered user.
     *
     * Registration is possible ONLY with an invitation:
     * - to an organization (organization_invitation + invitation_key): the account gets the role
     *   from the invitation right away, e.g. ORGANIZATION_OWNER,
     * - to a team (team_invitation): the account gets the default role and the invitation is accepted later.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $organizationInvitation = $this->organizationInvitation($input);
        $teamInvitation = $organizationInvitation ? null : $this->teamInvitation($input);

        if (! $organizationInvitation && ! $teamInvitation) {
            throw ValidationException::withMessages([
                'email' => 'Rejestracja jest możliwa tylko z zaproszenia.',
            ]);
        }

        // the phone number is stored in the international form (+48123456789)
        $input['phone'] = PhoneNumber::normalize($input['phone'] ?? null);

        Validator::make($input, [
            ...$this->profileRules(),
            'phone' => $this->phoneRules(),
            'password' => $this->passwordRules(),
            'invitation_key' => $organizationInvitation ? ['required', 'digits:6'] : ['nullable'],
            'terms' => ['accepted'],
            'privacy' => ['accepted'],
        ], [
            'terms.accepted' => 'Aby się zarejestrować, zaakceptuj regulamin.',
            'privacy.accepted' => 'Aby się zarejestrować, potwierdź zapoznanie się z informacją o przetwarzaniu danych (RODO).',
        ], ['invitation_key' => 'klucz zaproszenia', 'phone' => 'numer telefonu'])->validate();

        $invitedEmail = $organizationInvitation?->email ?? $teamInvitation->email;

        // The address in the form must be the one the invitation was sent to
        if (strtolower($input['email']) !== strtolower($invitedEmail)) {
            throw ValidationException::withMessages([
                'email' => 'Podaj adres e-mail, na który wysłano zaproszenie.',
            ]);
        }

        if ($organizationInvitation) {
            $this->verifyKey($organizationInvitation, (string) $input['invitation_key']);
        }

        return DB::transaction(function () use ($input, $organizationInvitation) {
            $user = new User([
                'name' => $input['name'],
                'email' => $input['email'],
                'phone' => $input['phone'],
                'password' => $input['password'],
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
            ]);

            // the role and the place in the structure come from the invitation (the default is the least privileged
            // role), never from the form: they are not mass assignable
            $user->forceFill([
                'role' => $organizationInvitation?->role ?? UserRole::Employee,
                'organization_id' => $organizationInvitation?->organization_id,
                'shop_headquarter_id' => $organizationInvitation?->shop_headquarter_id,
                'warehouse_headquarter_id' => $organizationInvitation?->warehouse_headquarter_id,
                'shop_branch_id' => $organizationInvitation?->shop_branch_id,
                'warehouse_branch_id' => $organizationInvitation?->warehouse_branch_id,
            ])->save();

            if ($organizationInvitation) {
                // The key from the email proves access to the mailbox, so the address is verified
                $user->markEmailAsVerified();
                $organizationInvitation->markAccepted();
            }

            $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            return $user;
        });
    }

    /**
     * The phone number is required and must be well formed.
     *
     * @return array<int, mixed>
     */
    private function phoneRules(): array
    {
        return [
            'required',
            'string',
            'max:20',
            fn ($attribute, $value, $fail) => PhoneNumber::isValid((string) $value)
                ? null
                : $fail('Podaj poprawny numer telefonu: 9 cyfr albo numer międzynarodowy zaczynający się od +.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function organizationInvitation(array $input): ?OrganizationInvitation
    {
        $token = $input['organization_invitation'] ?? null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        $invitation = OrganizationInvitation::where('code', $token)->first();

        if (! $invitation?->isPending()) {
            throw ValidationException::withMessages([
                'invitation_key' => 'Zaproszenie wygasło, zostało zablokowane lub już przyjęte.',
            ]);
        }

        return $invitation;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function teamInvitation(array $input): ?TeamInvitation
    {
        $code = $input['team_invitation'] ?? null;

        if (! is_string($code) || $code === '') {
            return null;
        }

        $invitation = TeamInvitation::where('code', $code)->first();

        if (! $invitation?->isPending()) {
            throw ValidationException::withMessages([
                'email' => 'Zaproszenie wygasło lub zostało już przyjęte.',
            ]);
        }

        return $invitation;
    }

    /**
     * Checks the key from the email; a wrong key increments the invitation's attempt counter.
     */
    private function verifyKey(OrganizationInvitation $invitation, string $key): void
    {
        // counted under a lock, so parallel guesses cannot outrun the limit
        $result = $invitation->attemptCode($key);

        if ($result === 'ok') {
            return;
        }

        throw ValidationException::withMessages([
            'invitation_key' => match ($result) {
                'locked' => 'Zaproszenie zostało zablokowane po zbyt wielu błędnych próbach.',
                'unavailable' => 'Zaproszenie wygasło, zostało zablokowane lub już przyjęte.',
                default => 'Nieprawidłowy klucz zaproszenia.',
            },
        ]);
    }
}
