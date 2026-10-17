<?php

namespace App\Services\Contracts;

use App\Models\User;

interface UnitMembershipServiceInterface
{
    /**
     * Whether the actor may switch the target's account on or off: the organization owner for the people of their
     * organization who work in its shops and warehouses, a branch leader for the workers of their own branch. Never
     * for themselves, for organization owners or platform accounts.
     */
    public function canSetActive(User $actor, User $target): bool;

    /**
     * The units of the person for the menu (see UnitMembershipRepositoryInterface::unitsOf); empty for platform accounts.
     *
     * \return list<array{type: string, level: string, name: string}>
     */
    public function overview(User $user): array;

    /**
     * Switches a person's account on or off (see canSetActive; 403 / a validation error otherwise). The person
     * who did it and the time are recorded. A deactivated person still logs in, but only sees the "account is not
     * active" page and may chat with the person who deactivated them and with the administrators.
     */
    public function setActive(User $actor, int $userId, bool $active): void;
}
