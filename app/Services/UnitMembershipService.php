<?php

namespace App\Services;

use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\Contracts\UnitMembershipRepositoryInterface;
use App\Services\Contracts\UnitMembershipServiceInterface;
use Illuminate\Validation\ValidationException;

/**
 * Switching the accounts of the people of the shop / warehouse structure on and off.
 */
class UnitMembershipService implements UnitMembershipServiceInterface
{
    public function __construct(private readonly UnitMembershipRepositoryInterface $memberships)
    {
        //
    }

    public function canSetActive(User $actor, User $target): bool
    {
        if ($actor->id === $target->id || $actor->organization_id === null || $actor->organization_id !== $target->organization_id) {
            return false;
        }

        // the people of the shop / warehouse structure only (never organization owners or platform accounts)
        if ($this->memberships->findPerson($target->organization_id, $target->id) === null) {
            return false;
        }

        if ($actor->role === UserRole::ORGANIZATION_OWNER) {
            return true;
        }

        // a branch leader switches the workers of their own branch
        foreach (HeadquarterType::cases() as $type) {
            if ($actor->role === $type->leaderRole() && $target->role === $type->workerRole()) {
                $branchId = $actor->{$type->branchColumn()};

                return $branchId !== null && $target->{$type->branchColumn()} === $branchId;
            }
        }

        return false;
    }

    public function setActive(User $actor, int $userId, bool $active): void
    {
        $leader = in_array($actor->role, [HeadquarterType::Shop->leaderRole(), HeadquarterType::Warehouse->leaderRole()], true);
        abort_unless(($actor->role === UserRole::ORGANIZATION_OWNER || $leader) && $actor->organization_id !== null, 403, 'Nie możesz włączać ani wyłączać kont.');

        $person = $this->memberships->findPerson($actor->organization_id, $userId);

        if ($person === null || ! $this->canSetActive($actor, $person)) {
            throw ValidationException::withMessages(['user_id' => 'Tej osoby nie możesz aktywować ani dezaktywować.']);
        }

        $this->memberships->setActive($person, $active, $actor);
    }
}
