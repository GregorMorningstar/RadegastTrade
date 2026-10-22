<?php

namespace App\Repositories;

use App\Enums\HeadquarterType;
use App\Enums\UnitLevel;
use App\Enums\UnitPosition;
use App\Enums\UserRole;
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
    public function findUnit(int $organizationId, HeadquarterType $type, UnitLevel $level, int $unitId): ShopHeadquarter|WarehouseHeadquarter|ShopBranch|WarehouseBranch|null
    {
        $model = match (true) {
            $level === UnitLevel::Headquarters && $type === HeadquarterType::Shop => ShopHeadquarter::class,
            $level === UnitLevel::Headquarters => WarehouseHeadquarter::class,
            $type === HeadquarterType::Shop => ShopBranch::class,
            default => WarehouseBranch::class,
        };

        return $model::query()->where('organization_id', $organizationId)->find($unitId);
    }

    public function unitsOf(User $user): array
    {
        if ($user->organization_id === null) {
            return [];
        }

        $units = [];

        foreach (HeadquarterType::cases() as $type) {
            $headquartersModel = $type === HeadquarterType::Shop ? ShopHeadquarter::class : WarehouseHeadquarter::class;
            $branchModel = $type === HeadquarterType::Shop ? ShopBranch::class : WarehouseBranch::class;
            $headquarterColumn = $type->headquarterColumn();
            $branchColumn = $type->branchColumn();

            $owner = $user->role === UserRole::ORGANIZATION_OWNER;
            $manager = $user->role === $type->ownerRole();
            $branchPerson = in_array($user->role, [$type->leaderRole(), $type->workerRole()], true);

            if (! $owner && ! $manager && ! $branchPerson) {
                continue;
            }

            $headquarters = $headquartersModel::query()
                ->where('organization_id', $user->organization_id)
                ->when(! $owner, fn ($query) => $query->whereKey($user->{$headquarterColumn}))
                ->orderBy('name')->limit(20)->get(['id', 'name']);

            foreach ($headquarters as $headquarter) {
                $units[] = ['type' => $type->value, 'level' => 'headquarters', 'name' => $headquarter->name];

                $branches = $branchModel::query()
                    ->where('organization_id', $user->organization_id)
                    ->where($headquarterColumn, $headquarter->id)
                    ->when($branchPerson, fn ($query) => $query->whereKey($user->{$branchColumn}))
                    ->orderBy('name')->limit(20)->get(['id', 'name']);

                foreach ($branches as $branch) {
                    $units[] = ['type' => $type->value, 'level' => 'branch', 'name' => $branch->name];
                }
            }

            // branches founded straight under the organization (no headquarters): the owner sees them all, a leader or a worker their own
            if ($owner || $branchPerson) {
                $standalone = $branchModel::query()
                    ->where('organization_id', $user->organization_id)
                    ->whereNull($headquarterColumn)
                    ->when($branchPerson, fn ($query) => $query->whereKey($user->{$branchColumn}))
                    ->orderBy('name')->limit(20)->get(['id', 'name']);

                foreach ($standalone as $branch) {
                    $units[] = ['type' => $type->value, 'level' => 'branch', 'name' => $branch->name];
                }
            }
        }

        return $units;
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

        if ($user->organization_id === null) {
            throw new \InvalidArgumentException('A person without an organization cannot be placed in a unit.');
        }

        return DB::transaction(function () use ($user, $type, $level, $unitId, $role) {
            $unit = $this->findUnit($user->organization_id, $type, $level, $unitId);

            if ($unit === null) {
                throw new \InvalidArgumentException('The unit is not in the organization of the person.');
            }

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
