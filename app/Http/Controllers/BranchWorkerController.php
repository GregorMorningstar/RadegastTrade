<?php

namespace App\Http\Controllers;

use App\Http\Requests\Platform\StoreMemberInvitationRequest;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Contracts\BranchWorkerServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workers of a shop or warehouse branch and their invitations (branch leader panel).
 */
class BranchWorkerController extends Controller
{
    public function __construct(private readonly BranchWorkerServiceInterface $workers)
    {
        //
    }

    public function index(Request $request): Response
    {
        $leader = $request->user();
        $type = $this->workers->typeOfLeader($leader);
        $branch = $this->workers->branchOf($leader);

        return Inertia::render($type->pageGroup().'/leader/workers/index', [
            'group' => $type->group(),
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'workers' => $this->workers->workers($leader)->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'last_login' => $user->last_login_local?->format('d.m.Y H:i'),
            ])->values(),
            'invitations' => $this->workers->pendingInvitations($leader)->map(fn (OrganizationInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'phone' => $invitation->phone,
                'expires_at' => $invitation->expires_at->format('d.m.Y H:i'),
                'locked' => $invitation->isLocked(),
            ])->values(),
        ]);
    }

    public function invite(StoreMemberInvitationRequest $request): RedirectResponse
    {
        $this->workers->invite($request->user(), $request->validated('email'), $request->validated('phone'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zaproszenie zostało wysłane.']);

        return to_route($request->user()->role->group().'.leader.workers.index');
    }

    public function cancelInvitation(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $this->workers->cancel($request->user(), $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zaproszenie zostało anulowane.']);

        return back();
    }
}
