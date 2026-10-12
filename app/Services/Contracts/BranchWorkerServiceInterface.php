<?php

namespace App\Services\Contracts;

use App\Enums\HeadquarterType;
use App\Models\OrganizationInvitation;
use App\Models\ShopBranch;
use App\Models\User;
use App\Models\WarehouseBranch;
use Illuminate\Database\Eloquent\Collection;

interface BranchWorkerServiceInterface
{
    public function typeOfLeader(User $leader): HeadquarterType;

    /**
     * The leader's branch, provided it belongs to their organization and headquarters (the whole key chain matches).
     */
    public function branchOf(User $leader): ShopBranch|WarehouseBranch;

    /**
     * Branch workers (accounts that accepted the invitation).
     *
     * @return Collection<int, User>
     */
    public function workers(User $leader): Collection;

    /**
     * Pending worker invitations to the branch.
     *
     * @return Collection<int, OrganizationInvitation>
     */
    public function pendingInvitations(User $leader): Collection;

    /**
     * Emails the link and key of the worker invitation. A new invitation revokes previous,
     * unaccepted ones for the same address and the same branch.
     */
    public function invite(User $leader, string $email, ?string $phone = null): OrganizationInvitation;

    /**
     * Cancels the worker invitation, provided it was sent from the logged-in leader's organization, headquarters and branch.
     */
    public function cancel(User $leader, OrganizationInvitation $invitation): void;
}
