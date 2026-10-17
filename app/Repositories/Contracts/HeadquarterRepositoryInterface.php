<?php

namespace App\Repositories\Contracts;

use App\Models\ShopHeadquarter;
use App\Models\WarehouseHeadquarter;
use Illuminate\Database\Eloquent\Collection;

interface HeadquarterRepositoryInterface
{
    /**
     * Organization headquarters together with managers and pending invitations.
     *
     * @return Collection<int, ShopHeadquarter|WarehouseHeadquarter>
     */
    public function forOrganization(int $organizationId): Collection;

    /** One headquarters of the organization (a headquarters of another organization is never found). */
    public function findForOrganization(int $organizationId, int $id): ShopHeadquarter|WarehouseHeadquarter|null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $organizationId, array $data): ShopHeadquarter|WarehouseHeadquarter;

    /**
     * Whether the organization already has a headquarters of this type with this name.
     */
    public function nameExists(int $organizationId, string $name): bool;

    /**
     * Whether the NIP is already stored for any organization or headquarters (shop and warehouse).
     */
    public function nipInUse(string $nip): bool;
}
