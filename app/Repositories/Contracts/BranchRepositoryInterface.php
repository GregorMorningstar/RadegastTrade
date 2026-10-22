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
     * A null headquarters creates a standalone branch (founded straight under the organization).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(int $organizationId, ?int $headquarterId, array $data): ShopBranch|WarehouseBranch;

    /**
     * Whether the headquarters already has a branch with this name; with no headquarters, whether the organization already
     * has a standalone branch with it.
     */
    public function nameExists(?int $headquarterId, string $name, ?int $organizationId = null): bool;

    /**
     * Branches of the organization that belong to no headquarters, with their people.
     *
     * @return Collection<int, ShopBranch|WarehouseBranch>
     */
    public function standalone(int $organizationId): Collection;

    /**
     * Whether the NIP is already stored for any organization, headquarters or branch.
     */
    public function nipInUse(string $nip): bool;
}
