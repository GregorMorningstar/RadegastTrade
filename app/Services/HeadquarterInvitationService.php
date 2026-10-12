<?php

namespace App\Services;

use App\Services\Contracts\HeadquarterInvitationServiceInterface;
use App\Services\Contracts\HeadquarterServiceInterface;
use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\OrganizationInvitation;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;
use App\Notifications\OrganizationInvitationNotification;
use App\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Headquarters manager invitations sent by the organization owner
 * (like organization owner invitations sent by the administrator).
 */
class HeadquarterInvitationService implements HeadquarterInvitationServiceInterface
{
    public function __construct(
        private readonly HeadquarterServiceInterface $headquarters,
        private readonly OrganizationInvitationRepositoryInterface $invitations,
    ) {
        //
    }

    /**
     * Emails the link and key. A new invitation revokes previous, unaccepted ones for the same address and headquarters.
     */
    public function invite(User $inviter, HeadquarterType $type, int $headquarterId, string $email, ?string $phone = null): OrganizationInvitation
    {
        // Safeguard: the headquarters' organization id must be the same as the logged-in user's
        $headquarter = $this->headquarters->findOwned($inviter, $type, $headquarterId);
        $role = $type->ownerRole();
        $email = strtolower($email);

        $this->ensureCanBeInvited($email, $role, $headquarter->organization_id, $headquarter, $inviter);

        $this->invitations->revokePendingForHeadquarter($headquarter, $email, $inviter);

        [$invitation, $verificationCode] = $this->invitations->issue(
            $inviter->organization, $email, $inviter, $role, $headquarter, $phone,
        );

        Notification::route('mail', $email)
            ->notify(new OrganizationInvitationNotification($invitation, $verificationCode));

        return $invitation;
    }

    /**
     * Invitation to create a NEW headquarters: the invited person provides the headquarters details after accepting.
     * The organization id comes from the logged-in owner's account (not from the form) and goes into the invitation
     * as a foreign key, and the headquarters created from this invitation gets the same organization_id.
     */
    public function inviteNew(User $inviter, HeadquarterType $type, string $email, ?string $phone = null): OrganizationInvitation
    {
        $organization = $this->headquarters->organizationOf($inviter);
        $role = $type->ownerRole();
        $email = strtolower($email);

        $this->ensureCanBeInvited($email, $role, $organization->id, null, $inviter);

        $this->invitations->revokePendingNew($organization, $role, $email, $inviter);

        [$invitation, $verificationCode] = $this->invitations->issue($organization, $email, $inviter, $role, null, $phone);

        Notification::route('mail', $email)
            ->notify(new OrganizationInvitationNotification($invitation, $verificationCode));

        return $invitation;
    }

    /**
     * Cancels the invitation, provided it was sent on behalf of the logged-in user's organization.
     */
    public function cancel(User $by, OrganizationInvitation $invitation): void
    {
        $organization = $this->headquarters->organizationOf($by);

        abort_unless($invitation->organization_id === $organization->id, 403, 'To zaproszenie należy do innej organizacji.');
        abort_unless(HeadquarterType::forRole($invitation->role) !== null, 403);

        $invitation->revoke($by);
    }

    /**
     * An existing account can accept the invitation only if it has no administrative role
     * and does not belong to another organization or headquarters.
     */
    private function ensureCanBeInvited(string $email, UserRole $role, int $organizationId, ShopHeadquarter|WarehouseHeadquarter|null $headquarter = null, ?User $inviter = null): void
    {
        if ($inviter && $inviter->email && strtolower($inviter->email) === $email) {
            throw ValidationException::withMessages(['email' => 'Nie możesz zaprosić samego siebie.']);
        }

        $existing = User::where('email', $email)->first();

        if ($existing === null) {
            return;
        }

        $sameHeadquarter = match (true) {
            $headquarter instanceof ShopHeadquarter => $existing->shop_headquarter_id === $headquarter->id,
            $headquarter instanceof WarehouseHeadquarter => $existing->warehouse_headquarter_id === $headquarter->id,
            default => false,
        };

        $foreignOrganization = $existing->organization_id && $existing->organization_id !== $organizationId;
        $otherHeadquarter = ($existing->shop_headquarter_id || $existing->warehouse_headquarter_id) && ! $sameHeadquarter;
        $wrongRole = ! in_array($existing->role, [UserRole::Employee, $role], true);

        if ($foreignOrganization || $otherHeadquarter || $wrongRole) {
            throw ValidationException::withMessages([
                'email' => 'Ten adres należy do konta, które nie może zostać kierownikiem tej siedziby.',
            ]);
        }
    }
}
