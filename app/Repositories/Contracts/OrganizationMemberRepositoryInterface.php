<?php

namespace App\Repositories\Contracts;

use App\Enums\HeadquarterType;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface OrganizationMemberRepositoryInterface
{
    /**
     * Owners of the organization.
     *
     * @return Collection<int, User>
     */
    public function owners(int $organizationId): Collection;

    /**
     * A page of headquarters of one type, of one organization or (null) of all organizations, with the loaded
     * "organization" relation. Every headquarters comes with two more loaded relations:
     * "staff" (people assigned directly to it, i.e. its managers) and "branchList" (its branches, each with
     * "staff": the leaders and workers of the branch).
     *
     * With a headquarters id only that one headquarters is returned (still limited by the organization, if given).
     * With a search term only headquarters that match are returned: by their own name, NIP, e-mail, phone or
     * address, by the organization name, by the name of any of their branches or by any person working in them
     * (name, e-mail, phone, barcode).
     *
     * @return LengthAwarePaginator<int, ShopHeadquarter|WarehouseHeadquarter>
     */
    public function paginateHeadquarters(?int $organizationId, HeadquarterType $type, int $perPage, ?string $search = null, ?int $headquarterId = null): LengthAwarePaginator;
}
