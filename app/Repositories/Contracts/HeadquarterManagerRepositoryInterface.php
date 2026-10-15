<?php

namespace App\Repositories\Contracts;

use App\Enums\HeadquarterType;
use App\Models\ShopBranch;
use App\Models\User;
use App\Models\WarehouseBranch;
use Illuminate\Support\Collection;

interface HeadquarterManagerRepositoryInterface
{
    /**
     * People of the organization who could manage a unit of the given type: its managers, branch leaders and
     * workers (organization owners excluded), with their current headquarters and branch loaded.
     *
     * @return Collection<int, User>
     */
    public function candidates(int $organizationId, HeadquarterType $type): Collection;

    /**
     * One such person, or null when the user is not an eligible member of the organization.
     */
    public function findCandidate(int $organizationId, HeadquarterType $type, int $userId): ?User;

    /**
     * Current managers of the headquarters (there may be several, e.g. for shift work).
     *
     * @return Collection<int, User>
     */
    public function managersOf(HeadquarterType $type, int $headquarterId): Collection;

    /**
     * Current leaders of the branch (there may be several, e.g. for shift work).
     *
     * @return Collection<int, User>
     */
    public function leadersOf(HeadquarterType $type, int $branchId): Collection;

    public function findBranch(HeadquarterType $type, int $branchId): ShopBranch|WarehouseBranch|null;

    /**
     * Makes the user one of the managers of the headquarters: the manager role, this headquarters, no branch.
     * Other managers stay. A person is in at most one unit, so they leave their previous headquarters or branch.
     */
    public function addManager(HeadquarterType $type, int $headquarterId, User $user): void;

    /**
     * Detaches the manager from the headquarters (they stay in the organization). False when they were not its manager.
     */
    public function removeManager(HeadquarterType $type, int $headquarterId, User $user): bool;

    /**
     * Changes the manager of the headquarters into a worker of the same group (shop / warehouse): the worker role
     * without a headquarters or branch, so they can later be placed in a branch. False when they were not its manager.
     */
    public function demoteManagerToWorker(HeadquarterType $type, int $headquarterId, User $user): bool;

    /**
     * Changes the leader of the branch into a worker of the same group who stays in that branch.
     * False when they were not a leader of that branch.
     */
    public function demoteLeaderToWorker(HeadquarterType $type, int $branchId, User $user): bool;

    /**
     * Makes the user one of the leaders of the branch: the leader role, the branch and its headquarters.
     * Other leaders stay; the user leaves their previous headquarters or branch.
     */
    public function addLeader(HeadquarterType $type, ShopBranch|WarehouseBranch $branch, User $user): void;

    /**
     * Detaches the leader from the branch and its headquarters (they stay in the organization).
     * False when they were not a leader of that branch.
     */
    public function removeLeader(HeadquarterType $type, int $branchId, User $user): bool;
}
