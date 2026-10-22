<?php

namespace App\Services\Contracts;

use App\Enums\HeadquarterType;
use App\Models\OrganizationInvitation;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\BranchRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface BranchServiceInterface
{
    public function repository(HeadquarterType $type): BranchRepositoryInterface;

    /**
     * Type of branches managed by the headquarters manager; other roles get 403.
     */
    public function typeOfManager(User $manager): HeadquarterType;

    /**
     * The manager's headquarters, provided its organization is the one the logged-in user belongs to.
     */
    public function headquarterOf(User $manager): ShopHeadquarter|WarehouseHeadquarter;

    /**
     * @return Collection<int, ShopBranch|WarehouseBranch>
     */
    public function listFor(User $manager): Collection;

    /**
     * Pending invitations to create a new branch in the manager's headquarters.
     *
     * @return Collection<int, OrganizationInvitation>
     */
    public function pendingInvitations(User $manager): Collection;

    /**
     * Emails the link and key of the invitation to create a branch; the invitee provides the branch details.
     */
    public function invite(User $manager, string $email, ?string $phone = null): OrganizationInvitation;

    /**
     * The organization owner invites the leader of a new branch in one of the organization's headquarters of the
     * given type: the invitation carries the organization and the headquarters (foreign keys). 403 for anyone but
     * the owner, and for a headquarters of another organization; 404 for a missing one.
     */
    public function inviteAsOwner(User $owner, HeadquarterType $type, int $headquarterId, string $email, ?string $phone = null): OrganizationInvitation;

    /**
     * Pending invitations to create a branch anywhere in the owner's organization, for the given type.
     *
     * @return Collection<int, OrganizationInvitation>
     */
    public function pendingInvitationsForOwner(User $owner, HeadquarterType $type): Collection;

    /**
     * The owner invites the leader of a shop or warehouse founded straight under the organization (no headquarters).
     */
    public function inviteStandaloneAsOwner(User $owner, HeadquarterType $type, string $email, ?string $phone = null): OrganizationInvitation;

    /**
     * Pending invitations of leaders of standalone branches of the given type.
     *
     * @return Collection<int, OrganizationInvitation>
     */
    public function pendingStandaloneInvitations(User $owner, HeadquarterType $type): Collection;

    /**
     * The branches of the organization that belong to no headquarters.
     *
     * @return Collection<int, ShopBranch|WarehouseBranch>
     */
    public function standaloneBranches(User $owner, HeadquarterType $type): Collection;

    /**
     * Attaches standalone branches to a headquarters (owner only); returns how many were attached.
     *
     * @param  array<int, int|string>  $branchIds
     */
    public function attachToHeadquarter(User $owner, HeadquarterType $type, int $headquarterId, array $branchIds): int;

    /**
     * Cancels the branch invitation, provided it was sent from the logged-in manager's organization and headquarters.
     */
    public function cancel(User $manager, OrganizationInvitation $invitation): void;

    /**
     * Branch type of the invited leader who has not created it yet; otherwise 403.
     */
    public function pendingSetupType(User $leader): HeadquarterType;

    /**
     * Whether the invited branch leader (an account with an organization and headquarters) still has to create the branch.
     */
    public function needsSetup(User $leader): bool;

    /**
     * Dashboard data for a branch leader or worker: the branch, its headquarters and the organization with barcodes.
     * An account without an assigned branch (e.g. a demo one) gets an empty dashboard.
     *
     * @return array{branch: array<string, mixed>|null}
     */
    public function dashboardData(User $leader): array;

    /**
     * Creates the branch for the invited leader: organization_id and headquarters come from their account,
     * i.e. from the accepted invitation (foreign key), not from the form.
     *
     * The NIP may equal the organization's or headquarters' NIP, but any other NIP must not already exist in the system.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForInvitee(User $leader, array $data): ShopBranch|WarehouseBranch;
}
