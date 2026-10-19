<?php

namespace App\Policies;

use App\Models\TradeInvitation;
use App\Models\User;
use App\Support\TradeAccess;

/**
 * Who sends, answers and withdraws invitations to trade cooperation. Neither a link nor a matching e-mail address is
 * enough to answer: the person has to represent the invited organization (its owner, or a manager of a unit).
 */
class TradeInvitationPolicy
{
    public function __construct(private readonly TradeAccess $access)
    {
        //
    }

    /** Sending (business_relationships.invite): the owner, a headquarters manager, a branch leader. */
    public function invite(User $user): bool
    {
        return $this->access->managedUnits($user) !== [];
    }

    /** Answering (business_relationships.accept): a person of the invited organization who manages a unit or owns it. */
    public function represent(User $user, TradeInvitation $invitation): bool
    {
        return $user->organization_id === $invitation->invitee_organization_id && $this->access->managedUnits($user) !== [];
    }

    /** Withdrawing: the owner of the inviting organization or the person who sent it. */
    public function cancel(User $user, TradeInvitation $invitation): bool
    {
        return $this->access->canTrade($user)
            && $user->organization_id === $invitation->inviter_organization_id
            && ($this->access->isOwner($user) || $invitation->invited_by === $user->id);
    }
}
