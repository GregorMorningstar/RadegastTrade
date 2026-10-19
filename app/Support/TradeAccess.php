<?php

namespace App\Support;

use App\Enums\HeadquarterType;
use App\Enums\UnitLevel;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which units a person acts for in the trade module, worked out only from the signed-in user (never from request data):
 *  - the organization owner: every unit of the organization;
 *  - a headquarters manager: their headquarters and its branches;
 *  - a branch leader: their branch;
 *  - a worker: only their branch (to look, never to manage).
 * Nothing is cached, so a change of the structure or of a role is seen at once.
 */
class TradeAccess
{
    public function isOwner(User $user): bool
    {
        return $this->active($user) && $user->role === UserRole::ORGANIZATION_OWNER;
    }

    /** Whether the person takes part in the trade module at all (an active account of an organization with a unit role). */
    public function canTrade(User $user): bool
    {
        if (! $this->active($user)) {
            return false;
        }

        return $this->isOwner($user) || $this->ownUnit($user) !== null;
    }

    /** The unit the person works in (null for the owner and platform accounts). */
    public function ownUnit(User $user): ?UnitRef
    {
        if (! $this->active($user) || $user->role === UserRole::ORGANIZATION_OWNER) {
            return null;
        }

        foreach (HeadquarterType::cases() as $type) {
            if ($user->role === $type->ownerRole() && $user->{$type->headquarterColumn()} !== null) {
                return new UnitRef($type, UnitLevel::Headquarters, (int) $user->{$type->headquarterColumn()});
            }

            if (in_array($user->role, [$type->leaderRole(), $type->workerRole()], true) && $user->{$type->branchColumn()} !== null) {
                return new UnitRef($type, UnitLevel::Branch, (int) $user->{$type->branchColumn()});
            }
        }

        return null;
    }

    /**
     * Units the person manages.
     *
     * @return list<UnitRef>
     */
    public function managedUnits(User $user): array
    {
        if (! $this->active($user)) {
            return [];
        }

        $organizationId = (int) $user->organization_id;

        if ($user->role === UserRole::ORGANIZATION_OWNER) {
            return $this->allUnits($organizationId);
        }

        foreach (HeadquarterType::cases() as $type) {
            if ($user->role === $type->ownerRole() && $user->{$type->headquarterColumn()} !== null) {
                $headquarterId = (int) $user->{$type->headquarterColumn()};
                $units = [new UnitRef($type, UnitLevel::Headquarters, $headquarterId)];

                $branches = (new UnitRef($type, UnitLevel::Branch, 0))->modelClass()::query()
                    ->where('organization_id', $organizationId)
                    ->where($type->headquarterColumn(), $headquarterId)
                    ->pluck('id');

                foreach ($branches as $id) {
                    $units[] = new UnitRef($type, UnitLevel::Branch, (int) $id);
                }

                return $units;
            }

            if ($user->role === $type->leaderRole() && $user->{$type->branchColumn()} !== null) {
                return [new UnitRef($type, UnitLevel::Branch, (int) $user->{$type->branchColumn()})];
            }
        }

        return [];
    }

    /**
     * Units the person may look at: those they manage and, for a worker, their own.
     *
     * @return list<UnitRef>
     */
    public function viewUnits(User $user): array
    {
        $units = $this->managedUnits($user);
        $own = $this->ownUnit($user);

        if ($own !== null && ! in_array($own->ref(), array_map(fn (UnitRef $unit) => $unit->ref(), $units), true)) {
            $units[] = $own;
        }

        return $units;
    }

    public function manages(User $user, UnitRef $unit): bool
    {
        return in_array($unit->ref(), array_map(fn (UnitRef $managed) => $managed->ref(), $this->managedUnits($user)), true);
    }

    /**
     * Every headquarters and branch of an organization (optionally only shops or only warehouses).
     *
     * @return list<UnitRef>
     */
    public function allUnits(int $organizationId, ?HeadquarterType $only = null): array
    {
        $units = [];

        foreach (HeadquarterType::cases() as $type) {
            if ($only !== null && $only !== $type) {
                continue;
            }

            foreach ([UnitLevel::Headquarters, UnitLevel::Branch] as $level) {
                $ids = (new UnitRef($type, $level, 0))->modelClass()::query()->where('organization_id', $organizationId)->orderBy('id')->pluck('id');

                foreach ($ids as $id) {
                    $units[] = new UnitRef($type, $level, (int) $id);
                }
            }
        }

        return $units;
    }

    /**
     * The active people who manage a unit directly (its headquarters manager or its branch leader); when there is nobody,
     * the owners of the organization.
     *
     * @return Collection<int, User>
     */
    public function managersOf(UnitRef $unit, int $organizationId): Collection
    {
        $role = $unit->level === UnitLevel::Headquarters ? $unit->type->ownerRole() : $unit->type->leaderRole();

        $managers = User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('role', $role->value)
            ->where($unit->level->column($unit->type), $unit->id)
            ->get();

        return $managers->isNotEmpty()
            ? $managers
            : User::query()->where('organization_id', $organizationId)->where('is_active', true)->where('role', UserRole::ORGANIZATION_OWNER->value)->get();
    }

    private function active(User $user): bool
    {
        return $user->organization_id !== null && $user->is_active !== false;
    }
}
