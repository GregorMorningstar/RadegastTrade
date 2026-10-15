<?php

namespace App\Repositories;

use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\OrganizationMemberRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentOrganizationMemberRepository implements OrganizationMemberRepositoryInterface
{
    /**
     * "%term%" with the LIKE wildcards of the user input escaped (null for an empty search).
     */
    private function likeTerm(?string $search): ?string
    {
        $search = trim((string) $search);

        return $search === '' ? null : '%'.addcslashes($search, '%_\\').'%';
    }

    public function owners(int $organizationId): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('role', UserRole::ORGANIZATION_OWNER)
            ->orderBy('name')
            ->get();
    }

    public function paginateHeadquarters(?int $organizationId, HeadquarterType $type, int $perPage, ?string $search = null, ?int $headquarterId = null): LengthAwarePaginator
    {
        $headquarterModel = $type === HeadquarterType::Shop ? ShopHeadquarter::class : WarehouseHeadquarter::class;
        $branchModel = $type === HeadquarterType::Shop ? ShopBranch::class : WarehouseBranch::class;
        $headquarterColumn = $type->headquarterColumn();
        $branchColumn = $type->branchColumn();
        $term = $this->likeTerm($search);

        $page = $headquarterModel::query()
            ->with('organization:id,name')
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($headquarterId !== null, fn ($query) => $query->whereKey($headquarterId))
            ->when($term !== null, fn ($query) => $query->where(function ($match) use ($term, $branchModel, $headquarterColumn) {
                // the headquarters itself, its organization, any of its branches or any person working in it
                $match->where('name', 'like', $term)
                    ->orWhere('NIP', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('street', 'like', $term)
                    ->orWhereHas('organization', fn ($organization) => $organization->where('name', 'like', $term))
                    ->orWhereIn('id', $branchModel::query()->select($headquarterColumn)->where('name', 'like', $term))
                    ->orWhereIn('id', User::query()
                        ->select($headquarterColumn)
                        ->whereNotNull($headquarterColumn)
                        ->where(fn ($person) => $person
                            ->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term)
                            ->orWhere('phone', 'like', $term)
                            ->orWhere('barcode', 'like', $term)));
            }))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);

        $ids = $page->getCollection()->pluck('id');

        // one query for all branches and one for all people of the page's headquarters
        $branches = $branchModel::query()
            ->whereIn($headquarterColumn, $ids)
            ->orderBy('name')
            ->get()
            ->groupBy($headquarterColumn);

        $people = User::query()
            ->whereIn($headquarterColumn, $ids)
            ->orderBy('name')
            ->get();

        $page->getCollection()->each(function ($headquarters) use ($branches, $people, $headquarterColumn, $branchColumn) {
            $mine = $people->where($headquarterColumn, $headquarters->id);

            $headquarters->setRelation('staff', $mine->whereNull($branchColumn)->values());
            $headquarters->setRelation('branchList', ($branches->get($headquarters->id) ?? collect())->each(
                fn ($branch) => $branch->setRelation('staff', $mine->where($branchColumn, $branch->id)->values()),
            )->values());
        });

        return $page;
    }
}
