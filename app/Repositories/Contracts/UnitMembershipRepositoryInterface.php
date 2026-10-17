<?php

namespace App\Repositories\Contracts;

use App\Enums\HeadquarterType;
use App\Enums\UnitLevel;
use App\Enums\UnitPosition;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\UnitMembership;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;

interface UnitMembershipRepositoryInterface
{
    /**
     * The unit (headquarters or branch) of the given type in the organization, or null when it does not exist there.
     */
    public function findUnit(int $organizationId, HeadquarterType $type, UnitLevel $level, int $unitId): ShopHeadquarter|WarehouseHeadquarter|ShopBranch|WarehouseBranch|null;

    public function forUser(User $user): ?UnitMembership;

    /**
     * The headquarters and branches of the organization that a person works with, at most 20 of each: the owner of the
     * organization has every headquarters (with their branches), a headquarters manager their headquarters and its
     * branches, a branch leader or worker their headquarters and branch.
     *
     * \return list<array{type: string, level: string, name: string}>
     */
    public function unitsOf(User $user): array;

    /**
     * A person of the organization who works in the shop / warehouse structure (a headquarters manager, a branch
     * leader or a worker): never an organization owner or a platform account. Null otherwise.
     */
    public function findPerson(int $organizationId, int $userId): ?User;

    /**
     * Switches the account on or off (users.is_active) and records who did it and when; switching it on clears both.
     * A deactivated person still logs in but only sees the "account is not active" page and the chat.
     */
    public function setActive(User $user, bool $active, ?User $by = null): void;

    /**
     * Puts the user in the unit in the position: one row per person, so any earlier membership is replaced. The role
     * and the unit columns of the account follow (a headquarters manager, a branch manager = leader, a branch worker),
     * and the headquarters of a branch is set as well. A headquarters has managers only.
     *
     * @throws \InvalidArgumentException for a worker of a headquarters, for a person without an organization and for a
     *                                   unit that is not in the person's organization
     */
    public function place(User $user, HeadquarterType $type, UnitLevel $level, int $unitId, UnitPosition $position): UnitMembership;

    /**
     * Recomputes the membership from the user's role and unit columns (called after every change of the account):
     * creates, moves or deletes the row so that it always matches the account.
     */
    public function syncFromUser(User $user): void;
}
