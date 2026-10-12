<?php

namespace App\Repositories;

use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\BranchRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared branch logic; subclasses only specify the model and the headquarters column (shop or warehouse).
 */
abstract class EloquentBranchRepository implements BranchRepositoryInterface
{
    /**
     * @return class-string<ShopBranch|WarehouseBranch>
     */
    abstract protected function model(): string;

    abstract protected function headquarterColumn(): string;

    /**
     * @return Builder<ShopBranch|WarehouseBranch>
     */
    private function query(): Builder
    {
        return ($this->model())::query();
    }

    public function forHeadquarter(int $headquarterId): Collection
    {
        return $this->query()
            ->where($this->headquarterColumn(), $headquarterId)
            ->with('users:id,shop_branch_id,warehouse_branch_id,name,email')
            ->orderBy('name')
            ->get();
    }

    public function create(int $organizationId, int $headquarterId, array $data): ShopBranch|WarehouseBranch
    {
        return $this->query()->create([
            ...$data,
            'organization_id' => $organizationId,
            $this->headquarterColumn() => $headquarterId,
        ]);
    }

    public function nameExists(int $headquarterId, string $name): bool
    {
        return $this->query()->where($this->headquarterColumn(), $headquarterId)->where('name', $name)->exists();
    }

    public function nipInUse(string $nip): bool
    {
        return Organization::where('NIP', $nip)->exists()
            || ShopHeadquarter::where('NIP', $nip)->exists()
            || WarehouseHeadquarter::where('NIP', $nip)->exists()
            || ShopBranch::where('NIP', $nip)->exists()
            || WarehouseBranch::where('NIP', $nip)->exists();
    }
}
