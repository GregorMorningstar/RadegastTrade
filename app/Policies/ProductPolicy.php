<?php

namespace App\Policies;

use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\User;

/**
 * Who may look at the product catalogue: the owner of the organization and the people of its shops and warehouses
 * (headquarters managers, branch leaders and workers), each only in their own organization. Platform accounts
 * (administrator, manager, employee) do not see the catalogue of an organization.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->organization_id === null) {
            return false;
        }

        if ($user->role === UserRole::ORGANIZATION_OWNER) {
            return true;
        }

        foreach (HeadquarterType::cases() as $type) {
            if (in_array($user->role, [$type->ownerRole(), $type->leaderRole(), $type->workerRole()], true)) {
                return true;
            }
        }

        return false;
    }
}
