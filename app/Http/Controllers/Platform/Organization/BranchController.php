<?php

namespace App\Http\Controllers\Platform\Organization;

use App\Enums\HeadquarterType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreMemberInvitationRequest;
use App\Models\OrganizationInvitation;
use App\Services\Contracts\BranchServiceInterface;
use App\Services\Contracts\HeadquarterServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branches of the shops or warehouses in the organization owner's panel: the owner invites the leaders of new
 * branches in any of the organization's headquarters (the headquarters managers do it for their own).
 */
class BranchController extends Controller
{
    public function __construct(
        private readonly BranchServiceInterface $branches,
        private readonly HeadquarterServiceInterface $headquarters,
    ) {
        //
    }

    public function index(Request $request, HeadquarterType $type): Response|RedirectResponse
    {
        // an owner without an organization first fills in its details
        if (! $request->user()->organization_id) {
            return to_route('platform.organization.setup');
        }

        $owner = $request->user();

        return Inertia::render('platform/organization/'.$type->value.'/branches/index', [
            'type' => $type->value,
            'headquarters' => $this->headquarters->listFor($owner, $type)->map->only(['id', 'name'])->values(),
            // branches founded straight under the organization, waiting to be attached to a headquarters created later
            'standalone' => $this->branches->standaloneBranches($owner, $type)->map(fn ($branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'barcode' => $branch->barcode,
                'people' => $branch->users->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role->label()])->values(),
            ])->values(),
            'newInvitations' => $this->branches->pendingInvitationsForOwner($owner, $type)
                ->concat($this->branches->pendingStandaloneInvitations($owner, $type))
                ->map(fn (OrganizationInvitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'phone' => $invitation->phone,
                    'headquarter' => ($invitation->shopHeadquarter ?? $invitation->warehouseHeadquarter)?->name,
                    'expires_at' => $invitation->expires_at->format('d.m.Y H:i'),
                    'locked' => $invitation->isLocked(),
                ])->values(),
        ]);
    }

    /**
     * Invitation of the leader of a shop or warehouse founded straight under the organization (no main headquarters).
     */
    public function inviteStandalone(StoreMemberInvitationRequest $request, HeadquarterType $type): RedirectResponse
    {
        $this->branches->inviteStandaloneAsOwner($request->user(), $type, $request->validated('email'), $request->validated('phone'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zaproszenie zostało wysłane.']);

        return back();
    }

    /**
     * Attaches branches that have no headquarters to a headquarters created later.
     */
    public function attach(Request $request, HeadquarterType $type, int $headquarter): RedirectResponse
    {
        $data = $request->validate([
            'branches' => ['required', 'array', 'min:1', 'max:200'],
            'branches.*' => ['integer'],
        ]);

        $count = $this->branches->attachToHeadquarter($request->user(), $type, $headquarter, $data['branches']);

        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 1 ? 'Placówka została dołączona do siedziby.' : 'Placówki zostały dołączone do siedziby.']);

        return back();
    }

    /**
     * Invitation of the leader of a new branch in the chosen headquarters (the leader provides the branch details
     * after accepting it).
     */
    public function invite(StoreMemberInvitationRequest $request, HeadquarterType $type, int $headquarter): RedirectResponse
    {
        $this->branches->inviteAsOwner($request->user(), $type, $headquarter, $request->validated('email'), $request->validated('phone'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zaproszenie zostało wysłane.']);

        return back();
    }
}
