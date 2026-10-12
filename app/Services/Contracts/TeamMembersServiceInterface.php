<?php

namespace App\Services\Contracts;

use App\Models\User;

interface TeamMembersServiceInterface
{
    /**
     * Everyone in the owner's organization: organizers, headquarters managers and branch leaders.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrganization(User $owner): array;

    /**
     * Headquarters team: the organizer (organization owners) plus the headquarters managers and its branch leaders,
     * including invitees who have not accepted yet.
     *
     * @return list<array<string, mixed>>
     */
    public function forHeadquarter(User $manager): array;

    /**
     * Branch team: the branch leader and workers. The leader also sees worker invitations
     * that are awaiting acceptance; a worker sees only people who are already on the team.
     *
     * @return list<array<string, mixed>>
     */
    public function forBranch(User $member): array;
}
