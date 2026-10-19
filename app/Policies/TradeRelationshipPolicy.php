<?php

namespace App\Policies;

use App\Models\TradeRelationship;
use App\Models\User;
use App\Support\TradeAccess;
use App\Support\UnitRef;

/**
 * Who sees and manages a cooperation (business_relationships.manage): a person of one of the two organizations who is
 * its owner, or a manager whose units cover every unit of their organization that is in the scope. A leader of one
 * branch therefore cannot suspend a cooperation that covers other units.
 */
class TradeRelationshipPolicy
{
    public function __construct(private readonly TradeAccess $access)
    {
        //
    }

    public function viewAny(User $user): bool
    {
        return $this->access->managedUnits($user) !== [];
    }

    public function view(User $user, TradeRelationship $relationship): bool
    {
        return $this->viewAny($user) && $relationship->sideOf((int) $user->organization_id) !== null;
    }

    public function manage(User $user, TradeRelationship $relationship): bool
    {
        $side = $this->view($user, $relationship) ? $relationship->sideOf((int) $user->organization_id) : null;

        if ($side === null) {
            return false;
        }

        if ($this->access->isOwner($user)) {
            return true;
        }

        $scope = $relationship->units()->where('side', $side->value)->whereNull('revoked_at')->pluck('unit_ref');

        return $scope->isNotEmpty() && $scope->every(fn (string $ref) => ($unit = UnitRef::parse($ref)) !== null && $this->access->manages($user, $unit));
    }
}
