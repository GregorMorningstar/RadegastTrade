<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcceptOrganizationInvitationController extends Controller
{
    /**
     * Strona przyjęcia zaproszenia: pole na 6-cyfrowy klucz z maila.
     *
     * Jak w zaproszeniach do zespołów, konto zakłada się zwykłą rejestracją (na adres, na który
     * wysłano zaproszenie), a zaproszenie przyjmuje się po zalogowaniu. Gość z linku trafia
     * na rejestrację (z polem na klucz) albo logowanie.
     */
    public function show(Request $request, OrganizationInvitation $invitation): Response|RedirectResponse
    {
        $user = $request->user();

        // Gość z aktywnym zaproszeniem: rejestracja (brak konta) lub logowanie (konto istnieje)
        if (! $user && $invitation->isPending()) {
            if (User::where('email', $invitation->email)->exists()) {
                $request->session()->put('url.intended', $request->fullUrl());

                return redirect()->route('login');
            }

            // Rejestracja z kluczem od razu: konto dostaje rolę z zaproszenia
            return redirect()->route('register', ['organization_invitation' => $invitation->code]);
        }

        return Inertia::render('auth/organization-invitation', [
            'organizationName' => $invitation->organization?->name,
            'email' => $invitation->email,
            'acceptUrl' => route('organization-invitations.store', $invitation),
            'resendUrl' => route('organization-invitations.resend', $invitation),
            'status' => match (true) {
                $invitation->accepted_at !== null => 'accepted',
                $invitation->isRevoked() => 'revoked',
                $invitation->isExpired() => 'expired',
                $invitation->isLocked() => 'locked',
                $user && ! $invitation->isFor($user) => 'wrong_account',
                default => 'ready',
            },
        ]);
    }

    /**
     * Wysyła nowy klucz na adres z zaproszenia (adres nie pochodzi od użytkownika, więc nie da się
     * wysłać wiadomości w inne miejsce). Nowy klucz unieważnia poprzedni i odblokowuje próby.
     */
    public function resend(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        if ($invitation->accepted_at !== null || $invitation->isRevoked() || $invitation->isExpired()) {
            throw ValidationException::withMessages([
                'resend' => 'Zaproszenie wygasło, zostało wycofane lub już przyjęte. Poproś administratora o nowe.',
            ]);
        }

        // Odstęp między kolejnymi kluczami (updated_at zmienia się przy każdym nowym kluczu)
        if ($invitation->updated_at->gt(now()->subSeconds(60))) {
            throw ValidationException::withMessages([
                'resend' => 'Nowy klucz można wysłać raz na minutę. Spróbuj za chwilę.',
            ]);
        }

        $this->sendNewKey($invitation);

        return back()->with('status', 'resent');
    }

    private function sendNewKey(OrganizationInvitation $invitation): void
    {
        $code = $invitation->regenerateCode();

        Notification::route('mail', $invitation->email)
            ->notify(new OrganizationInvitationNotification($invitation, $code));
    }

    /**
     * Przyjmuje zaproszenie po sprawdzeniu adresu e-mail konta i klucza z maila.
     */
    public function store(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        $user = $request->user();

        if (! $invitation->isFor($user)) {
            throw ValidationException::withMessages([
                'code' => 'To zaproszenie jest przeznaczone dla innego adresu e-mail.',
            ]);
        }

        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'code' => 'Zaproszenie wygasło, zostało zablokowane lub już przyjęte.',
            ]);
        }

        // Rola organizacji nie może nadpisać roli administracyjnej ani roli w sklepie/hurtowni
        if (! in_array($user->role, [UserRole::Employee, UserRole::ORGANIZATION_OWNER], true)) {
            throw ValidationException::withMessages([
                'code' => 'Twoje konto nie może zostać właścicielem organizacji.',
            ]);
        }

        // Użytkownik należy do jednej organizacji: nie przepisujemy go do innej ani do nowej
        if ($user->organization_id && $user->organization_id !== $invitation->organization_id) {
            throw ValidationException::withMessages([
                'code' => 'To konto jest już przypisane do innej organizacji.',
            ]);
        }

        // Błędny klucz zwiększa licznik prób, a po limicie zaproszenie się blokuje
        if (! $invitation->checkCode($request->string('code')->toString())) {
            $invitation->increment('attempts');

            throw ValidationException::withMessages([
                'code' => $invitation->isLocked()
                    ? 'Zaproszenie zostało zablokowane po zbyt wielu błędnych próbach.'
                    : 'Nieprawidłowy kod weryfikacyjny.',
            ]);
        }

        DB::transaction(function () use ($user, $invitation) {
            $user->forceFill([
                'role' => $invitation->role,
                'organization_id' => $invitation->organization_id,
            ])->save();

            $invitation->markAccepted();

            // Klucz z maila potwierdza dostęp do skrzynki, więc adres uznajemy za zweryfikowany
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }
        });

        return redirect()->route($user->role->dashboardRoute());
    }
}
