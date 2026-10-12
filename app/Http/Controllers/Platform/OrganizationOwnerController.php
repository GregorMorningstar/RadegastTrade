<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreOrganizationRequest;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\NipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationOwnerController extends Controller
{
    /**
     * Panel: dane organizacji (tylko do odczytu).
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
     * Dashboard: podsumowanie organizacji (siedziby, osoby) i jej kod kreskowy.
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
                ->get(['id', 'name', 'email', 'role', 'last_login_at', 'last_login_timezone'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->label(),
                    'last_login' => $user->last_login_local
                        ? $user->last_login_local->format('d.m.Y H:i').' ('.$user->last_login_local->getTimezone()->getName().')'
                        : null,
                ])
                ->values(),
        ]);
    }

    /**
     * Formularz uzupełnienia danych organizacji po przyjęciu zaproszenia.
     * Nazwę podaje właściciel, a e-mail (jego adres z zaproszenia) jest tylko do odczytu.
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
     * Tworzy organizację (z kodem kreskowym) i przypisuje ją zapraszanemu właścicielowi.
     * Nazwę podaje właściciel, a e-mail to jego adres z zaproszenia (wartość z formularza jest ignorowana).
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

            // zaproszenie wskazuje teraz utworzoną organizację
            $invitation?->forceFill(['organization_id' => $organization->id])->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Organizacja została dodana.']);

        return to_route('platform.organization.dashboard');
    }

    /**
     * Dane firmy z rejestru MF po NIP, do wstrzyknięcia w formularz.
     */
    public function nipLookup(Request $request, NipService $nipService): JsonResponse
    {
        $nip = $request->validate(['nip' => ['required', 'string', 'max:20']])['nip'];

        return response()->json($nipService->fetchByNip($nip));
    }

    /**
     * Przyjęte zaproszenie właściciela, któremu jeszcze nie utworzono organizacji.
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
     * Właściciel bez organizacji najpierw uzupełnia jej dane.
     */
    private function withOrganization(Request $request): ?RedirectResponse
    {
        return $request->user()->organization_id ? null : to_route('platform.organization.setup');
    }
}
