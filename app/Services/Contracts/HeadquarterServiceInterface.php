<?php

namespace App\Services\Contracts;

use App\Enums\HeadquarterType;
use App\Models\Organization;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\HeadquarterRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface HeadquarterServiceInterface
{
    public function repository(HeadquarterType $type): HeadquarterRepositoryInterface;

    /**
     * @return Collection<int, ShopHeadquarter|WarehouseHeadquarter>
     */
    public function listFor(User $owner, HeadquarterType $type): Collection;

    /**
     * The headquarters from the URL, provided it belongs to the logged-in user's organization.
     * A missing headquarters is a 404, and someone else's headquarters (different organization_id) is a 403.
     */
    public function findOwned(User $owner, HeadquarterType $type, int $id): ShopHeadquarter|WarehouseHeadquarter;

    /**
     * Manager dashboard data: their headquarters and the people assigned to it.
     *
     * @return array{headquarter: array<string, mixed>|null, stats: array{people: int}, people: list<array<string, mixed>>}
     */
    public function dashboardData(User $manager): array;

    /**
     * Headquarters type of the invited manager who has not created it yet; otherwise 403.
     */
    public function pendingSetupType(User $user): HeadquarterType;

    public function hasHeadquarter(User $user): bool;

    /**
     * Whether the invited manager still has to found the headquarters: an account with an organization and no
     * headquarters that has an accepted invitation which did not produce one yet.
     */
    public function needsSetup(User $user): bool;

    /**
     * Creates the headquarters for the invited manager: organization_id comes from their account,
     * i.e. from the accepted invitation (foreign key), not from the form.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForInvitee(User $user, array $data): ShopHeadquarter|WarehouseHeadquarter;

    /**
     * The logged-in owner's organization; an account without an organization or with another role has no access.
     */
    public function organizationOf(User $owner): Organization;
}
