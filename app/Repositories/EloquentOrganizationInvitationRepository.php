<?php

namespace App\Repositories;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class EloquentOrganizationInvitationRepository implements OrganizationInvitationRepositoryInterface
{
    public function hasAcceptedWithoutHeadquarters(User $user): bool
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $user->organization_id)
            ->where('role', $user->role->value)
            ->where('email', strtolower($user->email))
            ->whereNotNull('accepted_at')
            ->whereNull('shop_headquarter_id')
            ->whereNull('warehouse_headquarter_id')
            ->exists();
    }

    public function revokePendingForHeadquarter(ShopHeadquarter|WarehouseHeadquarter $headquarter, string $email, User $by): void
    {
        OrganizationInvitation::query()
            ->where($headquarter instanceof ShopHeadquarter ? 'shop_headquarter_id' : 'warehouse_headquarter_id', $headquarter->id)
            ->where('role', $headquarter instanceof ShopHeadquarter ? UserRole::SHOP_OWNER->value : UserRole::WAREHOUSE_OWNER->value)
            ->where('email', strtolower($email))
            ->where('is_active', true)
            ->whereNull('accepted_at')
            ->get()
            ->each(fn (OrganizationInvitation $previous) => $previous->revoke($by));
    }

    public function revokePendingNew(Organization $organization, UserRole $role, string $email, User $by): void
    {
        $this->newQuery($organization, $role)
            ->where('email', strtolower($email))
            ->get()
            ->each(fn (OrganizationInvitation $previous) => $previous->revoke($by));
    }

    public function pendingNew(Organization $organization, UserRole $role): Collection
    {
        return $this->newQuery($organization, $role)
            ->where('expires_at', '>', now())
            ->latest()
            ->get();
    }

    public function pendingNewBranch(ShopHeadquarter|WarehouseHeadquarter $headquarter, UserRole $leaderRole): Collection
    {
        return $this->newBranchQuery($headquarter, $leaderRole)->where('expires_at', '>', now())->latest()->get();
    }

    public function pendingNewBranchesOfOrganization(Organization $organization, UserRole $leaderRole): Collection
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $organization->id)
            ->where('role', $leaderRole->value)
            ->where(fn (Builder $query) => $query->whereNotNull('shop_headquarter_id')->orWhereNotNull('warehouse_headquarter_id'))
            ->whereNull('shop_branch_id')
            ->whereNull('warehouse_branch_id')
            ->where('is_active', true)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with(['shopHeadquarter:id,name', 'warehouseHeadquarter:id,name'])
            ->latest()
            ->get();
    }

    public function pendingNewStandaloneBranches(Organization $organization, UserRole $leaderRole): Collection
    {
        return $this->standaloneQuery($organization, $leaderRole)->where('expires_at', '>', now())->latest()->get();
    }

    public function revokePendingNewStandalone(Organization $organization, UserRole $leaderRole, string $email, User $by): void
    {
        $this->standaloneQuery($organization, $leaderRole)
            ->where('email', strtolower($email))
            ->get()
            ->each(fn (OrganizationInvitation $previous) => $previous->revoke($by));
    }

    public function hasAcceptedStandaloneWithoutBranch(User $user): bool
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $user->organization_id)
            ->where('role', $user->role->value)
            ->where('email', strtolower($user->email))
            ->whereNull('shop_headquarter_id')
            ->whereNull('warehouse_headquarter_id')
            ->whereNull('shop_branch_id')
            ->whereNull('warehouse_branch_id')
            ->whereNotNull('accepted_at')
            ->exists();
    }

    /**
     * A leader invitation with neither a headquarters nor a branch: the branch is founded straight under the organization.
     *
     * @return Builder<OrganizationInvitation>
     */
    private function standaloneQuery(Organization $organization, UserRole $leaderRole): Builder
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $organization->id)
            ->where('role', $leaderRole->value)
            ->whereNull('shop_headquarter_id')
            ->whereNull('warehouse_headquarter_id')
            ->whereNull('shop_branch_id')
            ->whereNull('warehouse_branch_id')
            ->where('is_active', true)
            ->whereNull('accepted_at');
    }

    public function revokePendingNewBranch(ShopHeadquarter|WarehouseHeadquarter $headquarter, UserRole $leaderRole, string $email, User $by): void
    {
        $this->newBranchQuery($headquarter, $leaderRole)
            ->where('email', strtolower($email))
            ->get()
            ->each(fn (OrganizationInvitation $previous) => $previous->revoke($by));
    }

    public function pendingForBranch(ShopBranch|WarehouseBranch $branch, UserRole $workerRole): Collection
    {
        return $this->branchQuery($branch, $workerRole)->where('expires_at', '>', now())->latest()->get();
    }

    public function revokePendingForBranch(ShopBranch|WarehouseBranch $branch, UserRole $workerRole, string $email, User $by): void
    {
        $this->branchQuery($branch, $workerRole)
            ->where('email', strtolower($email))
            ->get()
            ->each(fn (OrganizationInvitation $previous) => $previous->revoke($by));
    }

    /**
     * @return Builder<OrganizationInvitation>
     */
    private function branchQuery(ShopBranch|WarehouseBranch $branch, UserRole $workerRole): Builder
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $branch->organization_id)
            ->where($branch instanceof ShopBranch ? 'shop_branch_id' : 'warehouse_branch_id', $branch->id)
            ->where('role', $workerRole->value)
            ->where('is_active', true)
            ->whereNull('accepted_at');
    }

    /**
     * @return Builder<OrganizationInvitation>
     */
    private function newBranchQuery(ShopHeadquarter|WarehouseHeadquarter $headquarter, UserRole $leaderRole): Builder
    {
        $shop = $headquarter instanceof ShopHeadquarter;

        return OrganizationInvitation::query()
            ->where('organization_id', $headquarter->organization_id)
            ->where($shop ? 'shop_headquarter_id' : 'warehouse_headquarter_id', $headquarter->id)
            ->where('role', $leaderRole->value)
            ->whereNull($shop ? 'shop_branch_id' : 'warehouse_branch_id')
            ->where('is_active', true)
            ->whereNull('accepted_at');
    }

    /**
     * @return Builder<OrganizationInvitation>
     */
    private function newQuery(Organization $organization, UserRole $role): Builder
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $organization->id)
            ->where('role', $role->value)
            ->whereNull('shop_headquarter_id')
            ->whereNull('warehouse_headquarter_id')
            ->where('is_active', true)
            ->whereNull('accepted_at');
    }

    public function issue(Organization $organization, string $email, User $inviter, UserRole $role, ShopHeadquarter|WarehouseHeadquarter|null $headquarter = null, ?string $phone = null, ShopBranch|WarehouseBranch|null $branch = null): array
    {
        return OrganizationInvitation::issue($organization, $email, $inviter, $role, $headquarter, $phone, $branch);
    }
}
