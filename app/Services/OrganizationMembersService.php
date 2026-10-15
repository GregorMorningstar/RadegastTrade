<?php

namespace App\Services;

use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\OrganizationMemberRepositoryInterface;
use App\Services\Contracts\OrganizationMembersServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Breakdown of organizations into headquarters, branches and the people in them (platform staff only).
 */
class OrganizationMembersService implements OrganizationMembersServiceInterface
{
    /** Roles allowed to preview the structure. */
    private const VIEWERS = [UserRole::Admin, UserRole::Manager, UserRole::Employee];

    public function __construct(private readonly OrganizationMemberRepositoryInterface $members)
    {
        //
    }

    public function structure(User $viewer, Organization $organization, HeadquarterType $type, ?string $search = null, int $perPage = 5): array
    {
        $this->authorize($viewer);

        $page = $this->members->paginateHeadquarters($organization->id, $type, $this->perPage($perPage), $this->search($search));

        return [
            'owners' => $this->people($this->members->owners($organization->id)),
            'group' => $type->group(),
            'headquarters' => $this->headquartersPage($page),
        ];
    }

    public function headquarters(User $viewer, HeadquarterType $type, ?string $search = null, int $perPage = 5): array
    {
        $this->authorize($viewer);

        return $this->headquartersPage($this->members->paginateHeadquarters(null, $type, $this->perPage($perPage), $this->search($search)));
    }

    /** Search text: trimmed and capped; an empty search means no search. */
    private function search(?string $search): ?string
    {
        $search = trim((string) $search);

        return $search === '' ? null : mb_substr($search, 0, 100);
    }

    public function organizationHeadquarters(User $owner, HeadquarterType $type, ?string $search = null, int $perPage = 5): array
    {
        abort_unless($owner->role === UserRole::ORGANIZATION_OWNER && $owner->organization_id !== null, 403);

        return $this->headquartersPage(
            $this->members->paginateHeadquarters($owner->organization_id, $type, $this->perPage($perPage), $this->search($search)),
        );
    }

    public function ownHeadquarters(User $manager): array
    {
        $type = HeadquarterType::forRole($manager->role);
        abort_if($type === null || $manager->organization_id === null, 403);

        $headquarterId = $manager->{$type->headquarterColumn()};

        // a manager who has not created the headquarters yet sees an empty tree
        if ($headquarterId === null) {
            return ['data' => [], 'current_page' => 1, 'last_page' => 1, 'total' => 0];
        }

        return $this->headquartersPage(
            $this->members->paginateHeadquarters($manager->organization_id, $type, 1, null, $headquarterId),
        );
    }

    private function authorize(User $viewer): void
    {
        abort_unless(in_array($viewer->role, self::VIEWERS, true), 403);
    }

    private function perPage(int $perPage): int
    {
        return max(1, min($perPage, 20));
    }

    /**
     * @param  LengthAwarePaginator<int, ShopHeadquarter|WarehouseHeadquarter>  $page
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    private function headquartersPage(LengthAwarePaginator $page): array
    {
        return [
            'data' => $page->getCollection()->map(fn ($headquarters) => [
                ...$this->unit($headquarters),
                'organization' => $headquarters->organization?->name,
                'people' => $this->people($headquarters->staff),
                'branches' => $headquarters->branchList->map(fn ($branch) => [
                    ...$this->unit($branch),
                    'people' => $this->people($branch->staff),
                ])->values()->all(),
            ])->values()->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ];
    }

    /**
     * Contact details of a headquarters or a branch.
     *
     * @return array<string, mixed>
     */
    private function unit(ShopHeadquarter|WarehouseHeadquarter|ShopBranch|WarehouseBranch $unit): array
    {
        $street = trim(($unit->street ?? '').' '.($unit->building_number ?? '').($unit->apartment_number ? '/'.$unit->apartment_number : ''));
        $town = trim(($unit->postal_code ?? '').' '.($unit->city ?? ''));

        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'barcode' => $unit->barcode,
            'nip' => $unit->NIP,
            'email' => $unit->email,
            'phone' => $unit->phone,
            'address' => collect([$street, $town])->filter()->implode(', ') ?: null,
        ];
    }

    /**
     * @param  iterable<User>  $users
     * @return list<array<string, mixed>>
     */
    private function people(iterable $users): array
    {
        return collect($users)->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'barcode' => $user->barcode,
            'role' => $user->role->label(),
            'role_value' => $user->role->value,
            'is_active' => $user->is_active !== false,
        ])->values()->all();
    }
}
