<?php

namespace App\Repositories;

use App\Enums\HeadquarterType;
use App\Models\ShopBranch;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Repositories\Contracts\HeadquarterManagerRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentHeadquarterManagerRepository implements HeadquarterManagerRepositoryInterface
{
    public function candidates(int $organizationId, HeadquarterType $type): Collection
    {
        return $this->eligible($organizationId, $type)
            ->with(['shopHeadquarter:id,name', 'warehouseHeadquarter:id,name', 'shopBranch:id,name', 'warehouseBranch:id,name'])
            ->orderBy('name')
            ->get();
    }

    public function findCandidate(int $organizationId, HeadquarterType $type, int $userId): ?User
    {
        return $this->eligible($organizationId, $type)->whereKey($userId)->lockForUpdate()->first();
    }

    public function managersOf(HeadquarterType $type, int $headquarterId): Collection
    {
        return User::query()
            ->where($type->headquarterColumn(), $headquarterId)
            ->where('role', $type->ownerRole())
            ->orderBy('name')
            ->get();
    }

    public function leadersOf(HeadquarterType $type, int $branchId): Collection
    {
        return User::query()
            ->where($type->branchColumn(), $branchId)
            ->where('role', $type->leaderRole())
            ->orderBy('name')
            ->get();
    }

    public function findBranch(HeadquarterType $type, int $branchId): ShopBranch|WarehouseBranch|null
    {
        return ($type === HeadquarterType::Shop ? ShopBranch::class : WarehouseBranch::class)::find($branchId);
    }

    public function addManager(HeadquarterType $type, int $headquarterId, User $user): void
    {
        $user->forceFill([
            'role' => $type->ownerRole(),
            $type->headquarterColumn() => $headquarterId,
            $type->branchColumn() => null,
        ])->save();
    }

    public function removeManager(HeadquarterType $type, int $headquarterId, User $user): bool
    {
        if ($user->role !== $type->ownerRole() || $user->{$type->headquarterColumn()} !== $headquarterId) {
            return false;
        }

        $user->forceFill([$type->headquarterColumn() => null])->save();

        return true;
    }

    public function demoteManagerToWorker(HeadquarterType $type, int $headquarterId, User $user): bool
    {
        if ($user->role !== $type->ownerRole() || $user->{$type->headquarterColumn()} !== $headquarterId) {
            return false;
        }

        $user->forceFill([
            'role' => $type->workerRole(),
            $type->headquarterColumn() => null,
            $type->branchColumn() => null,
        ])->save();

        return true;
    }

    public function demoteLeaderToWorker(HeadquarterType $type, int $branchId, User $user): bool
    {
        if ($user->role !== $type->leaderRole() || $user->{$type->branchColumn()} !== $branchId) {
            return false;
        }

        $user->forceFill(['role' => $type->workerRole()])->save();

        return true;
    }

    public function addLeader(HeadquarterType $type, ShopBranch|WarehouseBranch $branch, User $user): void
    {
        $user->forceFill([
            'role' => $type->leaderRole(),
            $type->headquarterColumn() => $branch->{$type->headquarterColumn()},
            $type->branchColumn() => $branch->id,
        ])->save();
    }

    public function removeLeader(HeadquarterType $type, int $branchId, User $user): bool
    {
        if ($user->role !== $type->leaderRole() || $user->{$type->branchColumn()} !== $branchId) {
            return false;
        }

        $user->forceFill([$type->headquarterColumn() => null, $type->branchColumn() => null])->save();

        return true;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function eligible(int $organizationId, HeadquarterType $type)
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->whereIn('role', [$type->ownerRole(), $type->leaderRole(), $type->workerRole()]);
    }
}
