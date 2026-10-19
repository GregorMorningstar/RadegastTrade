<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreOrganizationRequest;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Contracts\NipServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationOwnerController extends Controller
{
    /**
     * Panel: organization details (read-only).
     */
    public function index(Request $request): Response|RedirectResponse
    {
        return $this->withOrganization($request) ?? Inertia::render('platform/organization/index', [
            'organization' => $request->user()->organization->only([
                'id', 'name', 'barcode', 'NIP', 'REGON', 'email', 'phone', 'street', 'building_number',
                'apartment_number', 'postal_code', 'city', 'country', 'is_active',
            ]),
        ]);
    }

    /**
     * Dashboard: organization summary (headquarters, people) and its barcode.
     */
    public function dashboard(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->withOrganization($request)) {
            return $redirect;
        }

        $organization = $request->user()->organization;

        return Inertia::render('platform/organization/dashboard/index', [
            'organization' => $organization->only(['id', 'name', 'NIP', 'barcode']),
            'stats' => [
                'warehouseHeadquarters' => $organization->warehouseHeadquarters()->count(),
                'shopHeadquarters' => $organization->shopHeadquarters()->count(),
                'people' => $organization->users()->count(),
            ],
            'people' => $organization->users()
                ->orderBy('name')
                ->paginate(10, ['id', 'name', 'email', 'role', 'avatar_path', 'last_login_at', 'last_login_timezone'])
                ->withQueryString()
                ->through(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->label(),
                    'is_me' => $user->id === $request->user()->id,
                    'avatar_url' => $user->avatar_url,
                    'last_login' => $user->last_login_local
                        ? $user->last_login_local->format('d.m.Y H:i').' ('.$user->last_login_local->getTimezone()->getName().')'
                        : null,
                ]),
        ]);
    }

    /**
     * Details of one person of the owner's organization (JSON for the small details window).
     * Only an explicit whitelist of fields is returned; a person from another organization is a 404 (no enumeration).
     */
    public function person(Request $request, int $user): JsonResponse
    {
        $person = $request->user()->organization?->users()
            ->with(['warehouseHeadquarter:id,name', 'shopHeadquarter:id,name', 'warehouseBranch:id,name', 'shopBranch:id,name'])
            ->find($user);

        abort_if($person === null, 404);

        $units = collect([
            $person->warehouseHeadquarter?->name,
            $person->shopHeadquarter?->name,
            $person->warehouseBranch?->name,
            $person->shopBranch?->name,
        ])->filter()->values();

        return response()->json([
            'id' => $person->id,
            'name' => $person->name,
            'email' => $person->email,
            'phone' => $person->phone,
            'role' => $person->role->label(),
            'units' => $units,
            'avatar_url' => $person->avatar_url,
            'is_active' => (bool) $person->is_active,
            'is_me' => $person->id === $request->user()->id,
            'last_login' => $person->last_login_local
                ? $person->last_login_local->format('d.m.Y H:i').' ('.$person->last_login_local->getTimezone()->getName().')'
                : null,
            'created_at' => $person->created_at?->format('d.m.Y'),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Form for completing organization details after accepting the invitation.
     * The owner provides the name, and the email (their address from the invitation) is read-only.
     */
    public function setup(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->organization_id) {
            return to_route('platform.organization.dashboard');
        }

        return Inertia::render('platform/organization/setup', [
            'email' => $user->email,
        ]);
    }

    /**
     * Creates the organization (with a barcode) and assigns it to the invited owner.
     * The owner provides the name, and the email is their address from the invitation (the form value is ignored).
     */
    public function storeSetup(StoreOrganizationRequest $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user->organization_id, 403, 'Masz już przypisaną organizację.');

        $invitation = $this->acceptedInvitation($user);
        $data = $request->validated();

        $data['name'] = trim($data['name']);
        $data['email'] = $user->email;

        DB::transaction(function () use ($user, $invitation, $data) {
            $organization = Organization::create($data);

            $user->forceFill(['organization_id' => $organization->id])->save();

            // the invitation now points to the created organization
            $invitation?->forceFill(['organization_id' => $organization->id])->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Organizacja została dodana.']);

        return to_route('platform.organization.dashboard');
    }

    /**
     * Company data from the MF register by NIP, to be injected into the form.
     */
    public function nipLookup(Request $request, NipServiceInterface $nipService): JsonResponse
    {
        $nip = $request->validate(['nip' => ['required', 'string', 'max:20']])['nip'];

        return response()->json($nipService->fetchByNip($nip));
    }

    /**
     * Accepted owner invitation whose organization has not been created yet.
     */
    private function acceptedInvitation(User $user): ?OrganizationInvitation
    {
        return OrganizationInvitation::query()
            ->where('email', strtolower($user->email))
            ->whereNull('organization_id')
            ->whereNotNull('accepted_at')
            ->latest('accepted_at')
            ->first();
    }

    /**
     * An owner without an organization first fills in its details.
     */
    private function withOrganization(Request $request): ?RedirectResponse
    {
        return $request->user()->organization_id ? null : to_route('platform.organization.setup');
    }
}
