<?php

namespace App\Http\Controllers;

use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Services\Contracts\TeamMembersServiceInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Team" view: people and invitations in the organization (organization owner) or in a shop headquarters
 * or warehouse (its manager).
 */
class TeamMembersController extends Controller
{
    public function __invoke(Request $request, TeamMembersServiceInterface $team): Response
    {
        $user = $request->user();

        if ($user->role === UserRole::ORGANIZATION_OWNER) {
            return Inertia::render('platform/organization/team/index', [
                'scope' => 'organization',
                'members' => $team->forOrganization($user),
            ]);
        }

        // branch leader and worker: the branch team
        if (HeadquarterType::forLeaderRole($user->role) || HeadquarterType::forWorkerRole($user->role)) {
            $folder = HeadquarterType::forLeaderRole($user->role) ? 'leader' : 'worker';

            return Inertia::render($user->role->pageGroup().'/'.$folder.'/team/index', [
                'scope' => 'branch',
                'members' => $team->forBranch($user),
            ]);
        }

        return Inertia::render($user->role->pageGroup().'/owner/team/index', [
            'scope' => 'headquarter',
            'members' => $team->forHeadquarter($user),
        ]);
    }
}
