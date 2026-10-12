<?php

namespace App\Repositories\Contracts;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use Illuminate\Database\Eloquent\Collection;

interface OrganizationInvitationRepositoryInterface
{
    /**
     * Revokes unaccepted, active invitations to this address for this headquarters.
     */
    public function revokePendingForHeadquarter(ShopHeadquarter|WarehouseHeadquarter $headquarter, string $email, User $by): void;

    /**
     * Revokes unaccepted, active invitations to this address to create a new headquarters in the organization.
     */
    public function revokePendingNew(Organization $organization, UserRole $role, string $email, User $by): void;

    /**
     * Pending invitations to create a new headquarters (the headquarters is created after the invitation is accepted).
     *
     * @return Collection<int, OrganizationInvitation>
     */
    public function pendingNew(Organization $organization, UserRole $role): Collection;

    /**
     * Pending leader invitations to create a new branch in the headquarters.
     *
     * @return Collection<int, OrganizationInvitation>
     */
    public function pendingNewBranch(ShopHeadquarter|WarehouseHeadquarter $headquarter, UserRole $leaderRole): Collection;

    /**
     * Revokes unaccepted, active invitations to this address to create a new branch in the headquarters.
     */
    public function revokePendingNewBranch(ShopHeadquarter|WarehouseHeadquarter $headquarter, UserRole $leaderRole, string $email, User $by): void;

    /**
     * Pending worker invitations to the branch.
     *
     * @return Collection<int, OrganizationInvitation>
     */
    public function pendingForBranch(ShopBranch|WarehouseBranch $branch, UserRole $workerRole): Collection;

    /**
     * Revokes unaccepted, active worker invitations to this address for this branch.
     */
    public function revokePendingForBranch(ShopBranch|WarehouseBranch $branch, UserRole $workerRole, string $email, User $by): void;

    /**
     * Creates the invitation (with the organization id as a foreign key) and returns it with the plain key (only to be sent by email).
     * Without a headquarters it is an invitation to create a new one: the invited person provides the details.
     *
     * @return array{0: OrganizationInvitation, 1: string}
     */
    public function issue(Organization $organization, string $email, User $inviter, UserRole $role, ShopHeadquarter|WarehouseHeadquarter|null $headquarter = null, ?string $phone = null, ShopBranch|WarehouseBranch|null $branch = null): array;
}
