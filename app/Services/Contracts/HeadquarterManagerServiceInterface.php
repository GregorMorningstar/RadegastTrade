<?php

namespace App\Services\Contracts;

use App\Enums\HeadquarterType;
use App\Models\User;

interface HeadquarterManagerServiceInterface
{
    /**
     * Managers of a headquarters and the people of the organization who can be added as managers (a headquarters
     * may have several managers, e.g. for shift work). Each candidate comes with a warning describing what the
     * change does to them: a person works in at most one unit, so they leave their current headquarters or branch
     * and change the role.
     *
     * A person who is the only leader of a branch is marked "blocked": a branch keeps at least one manager, so such a
     * person cannot be taken away from it (adding, dismissing, demoting and moving are refused with a validation error).
     *
     * Only the organization owner of the headquarters' organization may call it (403 otherwise, 404 for no headquarters).
     *
     * @return array{current: list<array{id: int, name: string, email: string}>, candidates: list<array{id: int, name: string, email: string, role: string, placement: string, current: bool, blocked: bool, warning: ?string}>}
     */
    public function candidates(User $owner, HeadquarterType $type, int $headquarterId): array;

    /**
     * Adds a person from the owner's organization as one more manager of the headquarters.
     */
    public function assign(User $owner, HeadquarterType $type, int $headquarterId, int $userId): void;

    /**
     * Detaches a manager from the headquarters (they stay in the organization and cannot found a new headquarters).
     */
    public function remove(User $owner, HeadquarterType $type, int $headquarterId, int $userId): void;

    /**
     * Changes a manager of the headquarters into a worker of the same group (a warehouse manager becomes a
     * warehouse worker, a shop manager a shop worker, never across groups). The worker has no headquarters or
     * branch afterwards.
     */
    public function demoteManager(User $owner, HeadquarterType $type, int $headquarterId, int $userId): void;

    /**
     * The same for the leaders of a branch. Allowed for the organization owner and for the manager of the
     * branch's headquarters, for no one else (403).
     *
     * @return array{current: list<array{id: int, name: string, email: string}>, candidates: list<array{id: int, name: string, email: string, role: string, placement: string, current: bool, blocked: bool, warning: ?string}>}
     */
    public function branchCandidates(User $actor, HeadquarterType $type, int $branchId): array;

    public function assignBranchLeader(User $actor, HeadquarterType $type, int $branchId, int $userId): void;

    public function removeBranchLeader(User $actor, HeadquarterType $type, int $branchId, int $userId): void;

    /**
     * Changes a leader of the branch into a worker of the same group who stays in that branch.
     */
    public function demoteBranchLeader(User $actor, HeadquarterType $type, int $branchId, int $userId): void;
}
