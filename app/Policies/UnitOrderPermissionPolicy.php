<?php

namespace App\Policies;

use App\Models\UnitOrderPermission;
use App\Models\User;
use App\Support\TradeAccess;

/**
 * Who delegates the right to place orders for a unit (unit_order_permissions.manage): the owner of the organization,
 * a headquarters manager for their headquarters and its branches, a branch leader for their branch. Nobody delegates
 * more than they manage.
 */
class UnitOrderPermissionPolicy
{
    public function __construct(private readonly TradeAccess $access)
    {
        //
    }

    public function viewAny(User $user): bool
    {
        return $this->access->managedUnits($user) !== [];
    }

    public function manage(User $user, UnitOrderPermission $permission): bool
    {
        $unit = $permission->unit();

        return $unit !== null
            && $permission->organization_id === $user->organization_id
            && $this->access->manages($user, $unit);
    }
}
