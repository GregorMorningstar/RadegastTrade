<?php

namespace App\Http\Controllers\Platform\Organization;

use App\Enums\HeadquarterType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreMemberInvitationRequest;
use App\Models\OrganizationInvitation;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use App\Services\Contracts\HeadquarterInvitationServiceInterface;
use App\Services\Contracts\HeadquarterServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shop and warehouse headquarters and the invitations of their managers (organization owner panel).
 */
class HeadquarterController extends Controller
{
    public function __construct(
        private readonly HeadquarterServiceInterface $headquarters,
        private readonly HeadquarterInvitationServiceInterface $invitations,
        private readonly OrganizationInvitationRepositoryInterface $invitationRepository,
    ) {
        //
    }

    public function index(Request $request, HeadquarterType $type): Response|RedirectResponse
    {
        // An owner without an organization first fills in its details
        if (! $request->user()->organization_id) {
            return to_route('platform.organization.setup');
        }

        $organization = $this->headquarters->organizationOf($request->user());

        return Inertia::render($type->page(), [
            'type' => $type->value,
            'newInvitations' => $this->invitationsData($this->invitationRepository->pendingNew($organization, $type->ownerRole())),
            'headquarters' => $this->headquarters->listFor($request->user(), $type)
                ->map(fn (ShopHeadquarter|WarehouseHeadquarter $headquarter) => [
                    'id' => $headquarter->id,
                    'name' => $headquarter->name,
                    'barcode' => $headquarter->barcode,
                    'NIP' => $headquarter->effectiveNip(),
                    'ownNip' => $headquarter->NIP !== null,
                    'address' => collect([
                        trim($headquarter->street.' '.$headquarter->building_number.($headquarter->apartment_number ? '/'.$headquarter->apartment_number : '')),
                        trim($headquarter->postal_code.' '.$headquarter->city),
                    ])->filter()->implode(', '),
                    'owners' => $headquarter->users->filter(fn (User $user) => $user->role === $type->ownerRole())->map(fn (User $user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'role' => $user->role->label(),
                    ])->values(),
                    'invitations' => $this->invitationsData($headquarter->invitations),
                ])->values(),
        ]);
    }

    /**
     * Invitation to create a new headquarters (the invited manager provides the details after accepting it).
     */
    public function inviteNew(StoreMemberInvitationRequest $request, HeadquarterType $type): RedirectResponse
    {
        $this->invitations->inviteNew($request->user(), $type, $request->validated('email'), $request->validated('phone'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zaproszenie zostało wysłane.']);

        return to_route('platform.organization.headquarters.index', $type);
    }

    public function invite(StoreMemberInvitationRequest $request, HeadquarterType $type, int $headquarter): RedirectResponse
    {
        $this->invitations->invite($request->user(), $type, $headquarter, $request->validated('email'), $request->validated('phone'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zaproszenie zostało wysłane.']);

        return to_route('platform.organization.headquarters.index', $type);
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

    public function cancelInvitation(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $this->invitations->cancel($request->user(), $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zaproszenie zostało anulowane.']);

        return back();
    }
}
