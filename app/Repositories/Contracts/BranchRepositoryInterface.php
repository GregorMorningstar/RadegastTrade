<?php

namespace App\Repositories\Contracts;

use App\Models\ShopBranch;
use App\Models\WarehouseBranch;
use Illuminate\Database\Eloquent\Collection;

interface BranchRepositoryInterface
{
    /**
     * Headquarters branches together with their leaders.
     *
     * @return Collection<int, ShopBranch|WarehouseBranch>
     */
    public function forHeadquarter(int $headquarterId): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $organizationId, int $headquarterId, array $data): ShopBranch|WarehouseBranch;

    /**
     * Whether the headquarters already has a branch with this name.
     */
    public function nameExists(int $headquarterId, string $name): bool;

    /**
     * Whether the NIP is already stored for any organization, headquarters or branch.
     */
    public function nipInUse(string $nip): bool;
}
