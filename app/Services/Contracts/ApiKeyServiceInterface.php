<?php

namespace App\Services\Contracts;

use App\Enums\HeadquarterType;
use App\Enums\UnitLevel;
use App\Models\ApiKey;
use App\Models\User;

/**
 * API keys of connectors. A key belongs to a unit (a headquarters or a branch): it is the key of the program that
 * sends the data of that unit (e.g. the connector on the computer of a shop).
 */
interface ApiKeyServiceInterface
{
    /**
     * Whether the person manages the keys of any unit (the organization owner, a headquarters manager, a branch leader).
     */
    public function canManage(User $user): bool;

    /**
     * The units whose keys the person manages (the owner: all, a headquarters manager: theirs and its branches, a
     * branch leader: their branch) with the state of each key, and the address the connector connects to. Never the
     * keys themselves.
     *
     * @return array{allowed: bool, api_url: string, units: list<array<string, mixed>>}
     */
    public function overview(User $user): array;

    /**
     * Generates a random key for the unit, stores only its hash and e-mails the key to the person. 403 for a unit the
     * person does not manage, 404 for a unit outside their organization. A unit with an active key needs that key
     * removed first (requestRemoval, confirmRemoval): otherwise a validation error. If the e-mail cannot be sent,
     * nothing is changed.
     *
     * @return array{allowed: bool, api_url: string, units: list<array<string, mixed>>}
     */
    public function issue(User $user, HeadquarterType $type, UnitLevel $level, int $unitId, string $name): array;

    /**
     * Sends a code to the person's e-mail that confirms removing the unit's key (valid 15 minutes, an earlier code stops
     * working). A validation error when the unit has no active key.
     *
     * @return array{allowed: bool, api_url: string, units: list<array<string, mixed>>}
     */
    public function requestRemoval(User $user, HeadquarterType $type, UnitLevel $level, int $unitId): array;

    /**
     * Removes (revokes) the unit's key when the code from the e-mail is right and was asked for by this very person. A
     * wrong, expired or missing code is a validation error; the fifth wrong code cancels it.
     */
    public function confirmRemoval(User $user, HeadquarterType $type, UnitLevel $level, int $unitId, string $code): void;

    /**
     * Shows the key of the unit again (to enter it in the connector): only to a person who manages the unit and only
     * after confirming with their password; each showing is noted. A validation error for a wrong password or when
     * there is no key to show (a key without a saved copy has to be removed and generated again).
     */
    public function reveal(User $user, HeadquarterType $type, UnitLevel $level, int $unitId, string $password): string;

    /**
     * The connector side: the active key the given key belongs to (null when it is unknown, revoked or its unit is gone).
     * Notes the use of the key.
     */
    public function authenticate(string $key, ?string $ip = null): ?ApiKey;

    /**
     * What the connector may learn about itself: its organization and unit.
     *
     * @return array{organization: string, unit: array{type: string, level: string, name: string}, key: array{name: string, prefix: string}}
     */
    public function describe(ApiKey $key): array;
}
