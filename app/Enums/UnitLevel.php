<?php

namespace App\Enums;

/**
 * Level of a unit in the structure: the main headquarters or one of its branches. The value is a URL segment.
 */
enum UnitLevel: string
{
    case Headquarters = 'headquarters';
    case Branch = 'branches';

    /**
     * Role an account has for the position in a unit of the given type: a headquarters manager, a branch
     * manager (leader) or a branch worker. A headquarters has no workers (null).
     */
    public function roleFor(HeadquarterType $type, UnitPosition $position): ?UserRole
    {
        return match (true) {
            $this === self::Headquarters && $position === UnitPosition::Manager => $type->ownerRole(),
            $this === self::Branch && $position === UnitPosition::Manager => $type->leaderRole(),
            $this === self::Branch && $position === UnitPosition::Worker => $type->workerRole(),
            default => null,
        };
    }

    /**
     * The users / unit_memberships column holding the unit id for this level and type.
     */
    public function column(HeadquarterType $type): string
    {
        return $this === self::Headquarters ? $type->headquarterColumn() : $type->branchColumn();
    }
}
