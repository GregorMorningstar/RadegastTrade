<?php

namespace App\Repositories\Contracts;

use App\Enums\HeadquarterType;
use App\Enums\UnitLevel;
use App\Models\ApiKey;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Storage of the API keys of connectors. A key belongs to a unit (a headquarters or a branch of the organization).
 */
interface ApiKeyRepositoryInterface
{
    /** The unit of the organization, or null when there is none like that in it (never a unit of another organization). */
    public function findUnit(int $organizationId, HeadquarterType $type, UnitLevel $level, int $unitId): ShopHeadquarter|WarehouseHeadquarter|ShopBranch|WarehouseBranch|null;

    /**
     * The units of the organization, each headquarters followed by its branches (at most 20 headquarters and 20
     * branches of each). With a branch id only that branch; with a headquarters id only that headquarters and its
     * branches; with neither every unit. An id of a unit needs its type (shops and warehouses have separate tables).
     *
     * @return list<array{type: HeadquarterType, level: UnitLevel, id: int, name: string, headquarter: ?string}>
     */
    public function units(int $organizationId, ?HeadquarterType $type = null, ?int $headquarterId = null, ?int $branchId = null): array;

    /**
     * The active (not revoked) keys of the organization, by "type:level:id" of the unit.
     *
     * @return Collection<string, ApiKey>
     */
    public function activeKeys(int $organizationId): Collection;

    public function activeForUnit(int $organizationId, HeadquarterType $type, UnitLevel $level, int $unitId): ?ApiKey;

    /**
     * Stores the hash of a new key of the unit and its encrypted copy (with the organization and the author set by the
     * backend). A unit has one
     * active key: when it already has one, a QueryException from the unique active_unit is thrown.
     */
    public function issue(User $by, ShopHeadquarter|WarehouseHeadquarter|ShopBranch|WarehouseBranch $unit, HeadquarterType $type, UnitLevel $level, string $name, string $prefix, string $hash, string $plainKey): ApiKey;

    /** Stores the hash of the code that confirms removing the key (replacing an earlier one) and resets the attempts. */
    public function storeRemovalCode(ApiKey $key, User $requestedBy, string $hash, CarbonInterface $expiresAt): void;

    /** Counts a wrong code; after the fifth the code is cancelled. Returns the number of attempts so far. */
    public function failRemovalAttempt(ApiKey $key): int;

    /** Revokes the key (it stops working and the unit may get a new one); notes when and by whom. */
    public function revoke(ApiKey $key, User $by): void;

    /** Notes that the key was shown to the person (when and by whom). */
    public function markRevealed(ApiKey $key, User $by): void;

    /** The active key with this hash (the key is looked up by its hash, never by the key itself). */
    public function findActiveByHash(string $hash): ?ApiKey;

    /** Notes the use of the key (time and address); written at most once a minute. */
    public function touch(ApiKey $key, ?string $ip): void;
}
