<?php

namespace App\Services;

use App\Services\Contracts\StructureListingServiceInterface;
use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;

/**
 * Structure listings (headquarters and branches of all organizations) for the platform administrator.
 */
class StructureListingService implements StructureListingServiceInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    private const LIMIT = 500;

    public function headquarters(User $viewer, HeadquarterType $type): array
    {
        $this->authorize($viewer);

        $model = $type === HeadquarterType::Shop ? ShopHeadquarter::class : WarehouseHeadquarter::class;

        return $model::query()
            ->with(['organization:id,name,NIP', 'users:id,shop_headquarter_id,warehouse_headquarter_id,name,email,role'])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (ShopHeadquarter|WarehouseHeadquarter $headquarter) => [
                'id' => $headquarter->id,
                'name' => $headquarter->name,
                'organization' => $headquarter->organization->name,
                'barcode' => $headquarter->barcode,
                'NIP' => $headquarter->effectiveNip(),
                'address' => $this->address($headquarter),
                'is_active' => $headquarter->is_active,
                'managers' => $this->people($headquarter->users->filter(fn (User $user) => $user->role === $type->ownerRole())),
            ])->values()->all();
    }

    /**
     * Shop or warehouse branches.
     *
     * @return list<array<string, mixed>>
     */
    public function branches(User $viewer, HeadquarterType $type): array
    {
        $this->authorize($viewer);

        $model = $type === HeadquarterType::Shop ? ShopBranch::class : WarehouseBranch::class;

        return $model::query()
            ->with(['organization:id,name,NIP', 'headquarter:id,name,NIP', 'users:id,shop_branch_id,warehouse_branch_id,name,email'])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (ShopBranch|WarehouseBranch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'organization' => $branch->organization->name,
                'headquarter' => $branch->headquarter->name,
                'barcode' => $branch->barcode,
                'NIP' => $branch->NIP ?: $branch->headquarter->NIP ?: $branch->organization->NIP,
                'address' => $this->address($branch),
                'is_active' => $branch->is_active,
                'managers' => $this->people($branch->users),
            ])->values()->all();
    }

    private function authorize(User $viewer): void
    {
        abort_unless($viewer->role === UserRole::Admin, 403);
    }

    private function address(ShopHeadquarter|WarehouseHeadquarter|ShopBranch|WarehouseBranch $place): string
    {
        return collect([
            trim($place->street.' '.$place->building_number.($place->apartment_number ? '/'.$place->apartment_number : '')),
            trim($place->postal_code.' '.$place->city),
        ])->filter()->implode(', ');
    }

    /**
     * @param  iterable<User>  $users
     * @return list<array{id: int, name: string, email: string}>
     */
    private function people(iterable $users): array
    {
        return collect($users)->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ])->values()->all();
    }
}
