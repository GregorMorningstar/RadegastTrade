<?php

namespace App\Http\Controllers;

use App\Http\Requests\Platform\StoreMemberInvitationRequest;
use App\Models\OrganizationInvitation;
use App\Models\ShopBranch;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Services\Contracts\BranchServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shop or warehouse branches and the invitations of their leaders (headquarters manager panel).
 */
class HeadquarterBranchController extends Controller
{
    public function __construct(private readonly BranchServiceInterface $branches)
    {
        //
    }

    public function index(Request $request): Response
    {
        $manager = $request->user();
        $type = $this->branches->typeOfManager($manager);

        return Inertia::render($type->pageGroup().'/owner/branches/index', [
            'group' => $type->group(),
            'headquarter' => $this->branches->headquarterOf($manager)->only(['id', 'name']),
            'newInvitations' => $this->invitationsData($this->branches->pendingInvitations($manager)),
            'branches' => $this->branches->listFor($manager)
                ->map(fn (ShopBranch|WarehouseBranch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'barcode' => $branch->barcode,
                    'NIP' => $branch->NIP,
                    'address' => collect([
                        trim($branch->street.' '.$branch->building_number.($branch->apartment_number ? '/'.$branch->apartment_number : '')),
                        trim($branch->postal_code.' '.$branch->city),
                    ])->filter()->implode(', '),
                    'managers' => $branch->users->filter(fn (User $user) => $user->role === $type->leaderRole())->map(fn (User $user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'role' => $user->role->label(),
                    ])->values(),
                ])->values(),
        ]);
    }

    /**
     * Invitation to create a new branch (the invited leader provides the details after accepting it).
     */
    public function invite(StoreMemberInvitationRequest $request): RedirectResponse
    {
        $this->branches->invite($request->user(), $request->validated('email'), $request->validated('phone'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zaproszenie zostało wysłane.']);

        return to_route($request->user()->role->group().'.owner.branches.index');
    }

    public function cancelInvitation(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $this->branches->cancel($request->user(), $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zaproszenie zostało anulowane.']);

        return back();
    }

    /**
     * @param  iterable<OrganizationInvitation>  $invitations
     * @return list<array{id: int, email: string, expires_at: string, locked: bool}>
     */
    private function invitationsData(iterable $invitations): array
    {
        return collect($invitations)->map(fn (OrganizationInvitation $invitation) => [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at->format('d.m.Y H:i'),
            'locked' => $invitation->isLocked(),
        ])->values()->all();
    }
}
