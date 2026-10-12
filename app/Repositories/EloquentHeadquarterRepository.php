<?php

namespace App\Repositories;

use App\Models\Organization;
use App\Enums\UserRole;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\HeadquarterRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared headquarters logic; subclasses only specify the model (shop or warehouse).
 */
abstract class EloquentHeadquarterRepository implements HeadquarterRepositoryInterface
{
    /**
     * @return class-string<ShopHeadquarter|WarehouseHeadquarter>
     */
    abstract protected function model(): string;

    /**
     * @return Builder<ShopHeadquarter|WarehouseHeadquarter>
     */
    private function query(): Builder
    {
        return ($this->model())::query();
    }

    public function forOrganization(int $organizationId): Collection
    {
        return $this->query()
            ->where('organization_id', $organizationId)
            ->with([
                'users:id,shop_headquarter_id,warehouse_headquarter_id,name,email',
                'invitations' => fn ($query) => $query
                    ->whereIn('role', [UserRole::SHOP_OWNER->value, UserRole::WAREHOUSE_OWNER->value])
                    ->where('is_active', true)
                    ->whereNull('accepted_at')
                    ->where('expires_at', '>', now())
                    ->latest(),
            ])
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): ShopHeadquarter|WarehouseHeadquarter|null
    {
        return $this->query()->find($id);
    }

    public function create(int $organizationId, array $data): ShopHeadquarter|WarehouseHeadquarter
    {
        return $this->query()->create([...$data, 'organization_id' => $organizationId]);
    }

    public function nameExists(int $organizationId, string $name): bool
    {
        return $this->query()
            ->where('organization_id', $organizationId)
            ->where('name', $name)
            ->exists();
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
