<?php

namespace App\Repositories;

use App\Enums\HeadquarterType;
use App\Enums\UnitLevel;
use App\Models\ApiKey;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EloquentApiKeyRepository implements ApiKeyRepositoryInterface
{
    public const MAX_ATTEMPTS = 5;

    private const LIMIT = 20;

    public function findUnit(int $organizationId, HeadquarterType $type, UnitLevel $level, int $unitId): ShopHeadquarter|WarehouseHeadquarter|ShopBranch|WarehouseBranch|null
    {
        return $this->model($type, $level)::query()->where('organization_id', $organizationId)->find($unitId);
    }

    public function units(int $organizationId, ?HeadquarterType $type = null, ?int $headquarterId = null, ?int $branchId = null): array
    {
        if ($type === null && ($headquarterId !== null || $branchId !== null)) {
            throw new InvalidArgumentException('Typ jednostki jest wymagany przy filtrowaniu po ID.');
        }

        $units = [];

        foreach (HeadquarterType::cases() as $kind) {
            if ($type !== null && $type !== $kind) {
                continue;
            }

            $headquartersModel = $this->model($kind, UnitLevel::Headquarters);
            $branchModel = $this->model($kind, UnitLevel::Branch);
            $headquarterColumn = $kind->headquarterColumn();

            // a branch id (a leader): that branch only, with the name of its headquarters
            if ($branchId !== null) {
                foreach ($branchModel::query()->where('organization_id', $organizationId)->whereKey($branchId)->with('headquarter:id,name')->get() as $branch) {
                    $units[] = $this->row($kind, UnitLevel::Branch, $branch->id, $branch->name, $branch->headquarter?->name);
                }

                continue;
            }

            $headquarters = $headquartersModel::query()
                ->where('organization_id', $organizationId)
                ->when($headquarterId !== null, fn ($query) => $query->whereKey($headquarterId))
                ->orderBy('name')->limit(self::LIMIT)->get(['id', 'name']);

            foreach ($headquarters as $headquarter) {
                $units[] = $this->row($kind, UnitLevel::Headquarters, $headquarter->id, $headquarter->name, null);

                foreach ($branchModel::query()->where('organization_id', $organizationId)->where($headquarterColumn, $headquarter->id)->orderBy('name')->limit(self::LIMIT)->get(['id', 'name']) as $branch) {
                    $units[] = $this->row($kind, UnitLevel::Branch, $branch->id, $branch->name, $headquarter->name);
                }
            }
        }

        return $units;
    }

    public function activeKeys(int $organizationId): Collection
    {
        return ApiKey::query()
            ->where('organization_id', $organizationId)
            ->whereNull('revoked_at')
            ->get()
            ->keyBy(fn (ApiKey $key) => (string) $key->active_unit);
    }

    public function activeForUnit(int $organizationId, HeadquarterType $type, UnitLevel $level, int $unitId): ?ApiKey
    {
        return ApiKey::query()
            ->where('organization_id', $organizationId)
            ->whereNull('revoked_at')
            ->where($level->column($type), $unitId)
            ->first();
    }

    public function issue(User $by, ShopHeadquarter|WarehouseHeadquarter|ShopBranch|WarehouseBranch $unit, HeadquarterType $type, UnitLevel $level, string $name, string $prefix, string $hash, string $plainKey): ApiKey
    {
        if ($by->organization_id === null || $unit->organization_id !== $by->organization_id) {
            throw new InvalidArgumentException('The unit is not in the organization of the person.');
        }

        // the organization, the unit and the author come from the backend, never from mass assignment
        $key = new ApiKey(['name' => $name, 'prefix' => $prefix, 'key_hash' => $hash]);
        $key->forceFill([
            'organization_id' => $by->organization_id,
            $level->column($type) => $unit->id,
            'active_unit' => $this->identity($type, $level, $unit->id),
            'key_encrypted' => $plainKey,
            'created_by' => $by->id,
        ])->save();

        return $key;
    }

    public function storeRemovalCode(ApiKey $key, User $requestedBy, string $hash, CarbonInterface $expiresAt): void
    {
        $key->forceFill([
            'removal_code_hash' => $hash,
            'removal_code_expires_at' => $expiresAt,
            'removal_attempts' => 0,
            'removal_requested_by' => $requestedBy->id,
        ])->save();
    }

    public function failRemovalAttempt(ApiKey $key): int
    {
        $attempts = (int) $key->removal_attempts + 1;

        $key->forceFill($attempts >= self::MAX_ATTEMPTS
            ? ['removal_code_hash' => null, 'removal_code_expires_at' => null, 'removal_attempts' => 0]
            : ['removal_attempts' => $attempts])->save();

        return $attempts;
    }

    public function revoke(ApiKey $key, User $by): void
    {
        $key->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $by->id,
            // there is nothing to show any more
            'key_encrypted' => null,
            'active_unit' => null,
            'removal_code_hash' => null,
            'removal_code_expires_at' => null,
            'removal_attempts' => 0,
        ])->save();
    }

    public function markRevealed(ApiKey $key, User $by): void
    {
        $key->forceFill(['revealed_at' => now(), 'revealed_by' => $by->id])->save();
    }

    public function findActiveByHash(string $hash): ?ApiKey
    {
        return ApiKey::query()->where('key_hash', $hash)->whereNull('revoked_at')->first();
    }

    public function touch(ApiKey $key, ?string $ip): void
    {
        if ($key->last_used_at !== null && $key->last_used_at->gt(now()->subMinute())) {
            return;
        }

        $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->save();
    }

    /**
     * @return class-string<ShopHeadquarter|WarehouseHeadquarter|ShopBranch|WarehouseBranch>
     */
    private function model(HeadquarterType $type, UnitLevel $level): string
    {
        return match (true) {
            $level === UnitLevel::Headquarters && $type === HeadquarterType::Shop => ShopHeadquarter::class,
            $level === UnitLevel::Headquarters => WarehouseHeadquarter::class,
            $type === HeadquarterType::Shop => ShopBranch::class,
            default => WarehouseBranch::class,
        };
    }

    private function identity(HeadquarterType $type, UnitLevel $level, int $unitId): string
    {
        return $type->value.':'.$level->value.':'.$unitId;
    }

    /**
     * @return array{type: HeadquarterType, level: UnitLevel, id: int, name: string, headquarter: ?string}
     */
    private function row(HeadquarterType $type, UnitLevel $level, int $id, string $name, ?string $headquarter): array
    {
        return ['type' => $type, 'level' => $level, 'id' => $id, 'name' => $name, 'headquarter' => $headquarter];
    }
}
