<?php

namespace App\Repositories;

use App\Enums\HeadquarterType;
use App\Enums\UnitLevel;
use App\Enums\UnitPosition;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\UnitMembership;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\UnitMembershipRepositoryInterface;
use Illuminate\Support\Facades\DB;

class EloquentUnitMembershipRepository implements UnitMembershipRepositoryInterface
{
    public function findUnit(HeadquarterType $type, UnitLevel $level, int $unitId): ShopHeadquarter|WarehouseHeadquarter|ShopBranch|WarehouseBranch|null
    {
        $model = match (true) {
            $level === UnitLevel::Headquarters && $type === HeadquarterType::Shop => ShopHeadquarter::class,
            $level === UnitLevel::Headquarters => WarehouseHeadquarter::class,
            $type === HeadquarterType::Shop => ShopBranch::class,
            default => WarehouseBranch::class,
        };

        return $model::find($unitId);
    }

    public function forUser(User $user): ?UnitMembership
    {
        return UnitMembership::query()->where('user_id', $user->id)->first();
    }

    public function findPerson(int $organizationId, int $userId): ?User
    {
        $roles = collect(HeadquarterType::cases())
            ->flatMap(fn (HeadquarterType $type) => [$type->ownerRole(), $type->leaderRole(), $type->workerRole()])
            ->all();

        return User::query()
            ->where('organization_id', $organizationId)
            ->whereIn('role', $roles)
            ->whereKey($userId)
            ->first();
    }

    public function setActive(User $user, bool $active, ?User $by = null): void
    {
        $user->forceFill([
            'is_active' => $active,
            'deactivated_by' => $active ? null : $by?->id,
            'deactivated_at' => $active ? null : now(),
        ])->save();
    }

    public function place(User $user, HeadquarterType $type, UnitLevel $level, int $unitId, UnitPosition $position): UnitMembership
    {
        $role = $level->roleFor($type, $position);

        if ($role === null) {
            throw new \InvalidArgumentException('A headquarters has managers only.');
        }

        return DB::transaction(function () use ($user, $type, $level, $unitId, $role) {
            $unit = $this->findUnit($type, $level, $unitId);

            // the account follows: the role, the unit and (for a branch) its headquarters; the other group's columns are cleared
            $user->forceFill([
                'role' => $role,
                'shop_headquarter_id' => null,
                'warehouse_headquarter_id' => null,
                'shop_branch_id' => null,
                'warehouse_branch_id' => null,
            ]);

            if ($level === UnitLevel::Branch) {
                $user->{$type->headquarterColumn()} = $unit?->{$type->headquarterColumn()};
            }

            $user->{$level->column($type)} = $unitId;
            $user->save();

            // the observer has already synchronized the row; make sure it exists even when nothing on the account changed
            $this->syncFromUser($user);

            return $this->forUser($user);
        });
    }

    public function syncFromUser(User $user): void
    {
        $target = $this->targetFor($user);

        if ($target === null) {
            UnitMembership::query()->where('user_id', $user->id)->delete();

            return;
        }

        [$type, $level, $unitId, $position] = $target;

        UnitMembership::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'organization_id' => $user->organization_id,
                'shop_headquarter_id' => null,
                'warehouse_headquarter_id' => null,
                'shop_branch_id' => null,
                'warehouse_branch_id' => null,
                $level->column($type) => $unitId,
                'position' => $position,
            ],
        );
    }

    /**
     * The unit and position the account describes, or null when it describes none: a headquarters manager has the
     * manager role and a headquarters, a branch leader or worker has the role and a branch.
     *
     * @return array{0: HeadquarterType, 1: UnitLevel, 2: int, 3: UnitPosition}|null
     */
    private function targetFor(User $user): ?array
    {
        if ($user->organization_id === null) {
            return null;
        }

        foreach (HeadquarterType::cases() as $type) {
            $target = $this->targetOfType($user, $type);

            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    /**
     * @return array{0: HeadquarterType, 1: UnitLevel, 2: int, 3: UnitPosition}|null
     */
    private function targetOfType(User $user, HeadquarterType $type): ?array
    {
        $headquarterId = $user->{$type->headquarterColumn()};
        $branchId = $user->{$type->branchColumn()};

        return match (true) {
            $user->role === $type->ownerRole() && $headquarterId !== null => [$type, UnitLevel::Headquarters, (int) $headquarterId, UnitPosition::Manager],
            $user->role === $type->leaderRole() && $branchId !== null => [$type, UnitLevel::Branch, (int) $branchId, UnitPosition::Manager],
            $user->role === $type->workerRole() && $branchId !== null => [$type, UnitLevel::Branch, (int) $branchId, UnitPosition::Worker],
            default => null,
        };
    }
}
