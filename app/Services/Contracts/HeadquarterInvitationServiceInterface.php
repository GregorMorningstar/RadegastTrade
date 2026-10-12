<?php

namespace App\Services\Contracts;

use App\Enums\HeadquarterType;
use App\Models\OrganizationInvitation;
use App\Models\User;

interface HeadquarterInvitationServiceInterface
{
    /**
     * Emails the link and key. A new invitation revokes previous, unaccepted ones for the same address and headquarters.
     */
    public function invite(User $inviter, HeadquarterType $type, int $headquarterId, string $email, ?string $phone = null): OrganizationInvitation;

    /**
     * Invitation to create a NEW headquarters: the invited person provides the headquarters details after accepting.
     * The organization id comes from the logged-in owner's account (not from the form) and goes into the invitation
     * as a foreign key, and the headquarters created from this invitation gets the same organization_id.
     */
    public function inviteNew(User $inviter, HeadquarterType $type, string $email, ?string $phone = null): OrganizationInvitation;

    /**
     * Cancels the invitation, provided it was sent on behalf of the logged-in user's organization.
     */
    public function cancel(User $by, OrganizationInvitation $invitation): void;
}
