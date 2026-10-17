<?php

namespace App\Policies;

use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;

/**
 * Who manages the API keys of connectors, and for which units:
 *  - the owner of the organization: every unit of the organization;
 *  - a headquarters manager: their headquarters and its branches;
 *  - a branch leader: their branch.
 * Workers and platform accounts (administrator, manager, employee) have no keys. The unit always has to be in the
 * person's own organization.
 */
class ApiKeyPolicy
{
    /** Whether the person manages the keys of any unit (the settings page, the menu link). */
    public function manage(User $user): bool
    {
        if ($user->organization_id === null || $user->is_active === false) {
            return false;
        }

        if ($user->role === UserRole::ORGANIZATION_OWNER) {
            return true;
        }

        foreach (HeadquarterType::cases() as $type) {
            if ($user->role === $type->ownerRole() && $user->{$type->headquarterColumn()} !== null) {
                return true;
            }

            if ($user->role === $type->leaderRole() && $user->{$type->branchColumn()} !== null) {
                return true;
            }
        }

        return false;
    }

    /** Whether the person manages the key of this very unit. */
    public function manageUnit(User $user, ShopHeadquarter|WarehouseHeadquarter|ShopBranch|WarehouseBranch $unit): bool
    {
        if (! $this->manage($user) || $unit->organization_id !== $user->organization_id) {
            return false;
        }

        if ($user->role === UserRole::ORGANIZATION_OWNER) {
            return true;
        }

        $type = $unit instanceof ShopHeadquarter || $unit instanceof ShopBranch ? HeadquarterType::Shop : HeadquarterType::Warehouse;
        $isBranch = $unit instanceof ShopBranch || $unit instanceof WarehouseBranch;

        // a headquarters manager: their headquarters and the branches of it
        if ($user->role === $type->ownerRole()) {
            $headquarterId = $isBranch ? $unit->{$type->headquarterColumn()} : $unit->id;

            return $headquarterId === $user->{$type->headquarterColumn()};
        }

        // a branch leader: their own branch only
        return $user->role === $type->leaderRole() && $isBranch && $unit->id === $user->{$type->branchColumn()};
    }
}
