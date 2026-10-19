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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcceptOrganizationInvitationController extends Controller
{
    /** How many new keys one invitation may get in an hour. */
    private const RESENDS_PER_HOUR = 3;

    /**
     * Invitation acceptance page: a field for the 6-digit key from the email.
     *
     * As with team invitations, the account is created through regular registration (for the address
     * the invitation was sent to), and the invitation is accepted after logging in. A guest following the link
     * lands on registration (with a key field) or on login.
     */
    public function show(Request $request, OrganizationInvitation $invitation): Response|RedirectResponse
    {
        $user = $request->user();

        // Guest with an active invitation: registration (no account) or login (account exists)
        if (! $user && $invitation->isPending()) {
            if (User::where('email', $invitation->email)->exists()) {
                $request->session()->put('url.intended', $request->fullUrl());

                return redirect()->route('login');
            }

            // Registration with the key right away: the account gets the role from the invitation
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
     * Sends a new key to the invitation's address (the address does not come from the user, so it is not possible
     * to send the message elsewhere). A new key invalidates the previous one and unlocks attempts.
     */
    public function resend(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        if ($invitation->accepted_at !== null || $invitation->isRevoked() || $invitation->isExpired()) {
            throw ValidationException::withMessages([
                'resend' => 'Zaproszenie wygasło, zostało wycofane lub już przyjęte. Poproś administratora o nowe.',
            ]);
        }

        // Interval between consecutive keys (updated_at changes with every new key)
        if ($invitation->updated_at->gt(now()->subSeconds(60))) {
            throw ValidationException::withMessages([
                'resend' => 'Nowy klucz można wysłać raz na minutę. Spróbuj za chwilę.',
            ]);
        }

        // A new key also resets the counter of wrong attempts, and this route needs no login (the link is enough), so it is
        // capped per invitation: otherwise it would be a way to keep guessing the 6 digits and to flood the mailbox
        $limit = 'org-invitation-resend:'.$invitation->id;

        if (RateLimiter::tooManyAttempts($limit, self::RESENDS_PER_HOUR)) {
            throw ValidationException::withMessages([
                'resend' => 'Wysłano już kilka nowych kluczy. Spróbuj za godzinę albo poproś o nowe zaproszenie.',
            ]);
        }

        RateLimiter::hit($limit, 3600);

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
     * Accepts the invitation after checking the account's email address and the key from the email.
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

        // The invitation role cannot overwrite an administrative role or any other role (the default account is Employee)
        if (! in_array($user->role, [UserRole::Employee, $invitation->role], true)) {
            throw ValidationException::withMessages([
                'code' => 'Twoje konto nie może przyjąć tej roli.',
            ]);
        }

        // A user belongs to one organization: we do not move them to another or to a new one
        if ($user->organization_id && $user->organization_id !== $invitation->organization_id) {
            throw ValidationException::withMessages([
                'code' => 'To konto jest już przypisane do innej organizacji.',
            ]);
        }

        // A wrong key increments the attempt counter (under a lock, so parallel guesses cannot outrun it) and the
        // invitation locks after the limit
        $attempt = $invitation->attemptCode($request->string('code')->toString());

        if ($attempt !== 'ok') {
            throw ValidationException::withMessages([
                'code' => match ($attempt) {
                    'locked' => 'Zaproszenie zostało zablokowane po zbyt wielu błędnych próbach.',
                    'unavailable' => 'Zaproszenie wygasło, zostało zablokowane lub już przyjęte.',
                    default => 'Nieprawidłowy kod weryfikacyjny.',
                },
            ]);
        }

        // A manager belongs to one headquarters: we do not move them to another
        $otherHeadquarter = ($user->shop_headquarter_id && $user->shop_headquarter_id !== $invitation->shop_headquarter_id)
            || ($user->warehouse_headquarter_id && $user->warehouse_headquarter_id !== $invitation->warehouse_headquarter_id);

        // A leader and a worker belong to one branch: we do not move them to another
        $otherHeadquarter = $otherHeadquarter
            || ($user->shop_branch_id && $invitation->shop_branch_id && $user->shop_branch_id !== $invitation->shop_branch_id)
            || ($user->warehouse_branch_id && $invitation->warehouse_branch_id && $user->warehouse_branch_id !== $invitation->warehouse_branch_id);

        if ($otherHeadquarter) {
            throw ValidationException::withMessages([
                'code' => 'To konto jest już przypisane do innej siedziby.',
            ]);
        }

        DB::transaction(function () use ($user, $invitation) {
            // the number from the invitation goes to an account that has none of its own (and nobody else has it)
            if ($invitation->phone && ! $user->phone && ! User::where('phone', $invitation->phone)->exists()) {
                $user->forceFill(['phone' => $invitation->phone]);
            }

            $user->forceFill([
                'role' => $invitation->role,
                'organization_id' => $invitation->organization_id,
                'shop_headquarter_id' => $invitation->shop_headquarter_id,
                'warehouse_headquarter_id' => $invitation->warehouse_headquarter_id,
                'shop_branch_id' => $invitation->shop_branch_id,
                'warehouse_branch_id' => $invitation->warehouse_branch_id,
            ])->save();

            $invitation->markAccepted();

            // The key from the email proves access to the mailbox, so we treat the address as verified
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }
        });

        return redirect()->route($user->role->dashboardRoute());
    }
}
