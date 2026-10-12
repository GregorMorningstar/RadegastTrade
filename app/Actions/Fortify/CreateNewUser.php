<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\OrganizationInvitation;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private CreateTeam $createTeam)
    {
        //
    }

    /**
     * Validate and create a newly registered user.
     *
     * Rejestracja jest możliwa WYŁĄCZNIE z zaproszenia:
     * - do organizacji (organization_invitation + invitation_key): konto od razu dostaje rolę
     *   z zaproszenia, np. ORGANIZATION_OWNER,
     * - do zespołu (team_invitation): konto dostaje domyślną rolę, a zaproszenie przyjmuje się potem.
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

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'invitation_key' => $organizationInvitation ? ['required', 'digits:6'] : ['nullable'],
            'terms' => ['accepted'],
            'privacy' => ['accepted'],
        ], [
            'terms.accepted' => 'Aby się zarejestrować, zaakceptuj regulamin.',
            'privacy.accepted' => 'Aby się zarejestrować, potwierdź zapoznanie się z informacją o przetwarzaniu danych (RODO).',
        ], ['invitation_key' => 'klucz zaproszenia'])->validate();

        $invitedEmail = $organizationInvitation?->email ?? $teamInvitation->email;

        // Adres w formularzu musi być tym, na który wysłano zaproszenie
        if (strtolower($input['email']) !== strtolower($invitedEmail)) {
            throw ValidationException::withMessages([
                'email' => 'Podaj adres e-mail, na który wysłano zaproszenie.',
            ]);
        }

        if ($organizationInvitation) {
            $this->verifyKey($organizationInvitation, (string) $input['invitation_key']);
        }

        return DB::transaction(function () use ($input, $organizationInvitation) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                // domyślna, najmniej uprzywilejowana rola albo rola z zaproszenia do organizacji
                'role' => $organizationInvitation?->role ?? UserRole::Employee,
                'organization_id' => $organizationInvitation?->organization_id,
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
            ]);

            if ($organizationInvitation) {
                // Klucz z maila potwierdza dostęp do skrzynki, więc adres jest zweryfikowany
                $user->markEmailAsVerified();
                $organizationInvitation->markAccepted();
            }

            $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            return $user;
        });
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
     * Sprawdza klucz z maila; błędny klucz zwiększa licznik prób zaproszenia.
     */
    private function verifyKey(OrganizationInvitation $invitation, string $key): void
    {
        if ($invitation->checkCode($key)) {
            return;
        }

        $invitation->increment('attempts');

        throw ValidationException::withMessages([
            'invitation_key' => $invitation->isLocked()
                ? 'Zaproszenie zostało zablokowane po zbyt wielu błędnych próbach.'
                : 'Nieprawidłowy klucz zaproszenia.',
        ]);
    }
}
