<?php

namespace App\Http\Controllers;

use App\Http\Requests\Platform\StoreHeadquarterRequest;
use App\Services\Contracts\HeadquarterServiceInterface;
use App\Services\Contracts\NipServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Completing headquarters details by a manager invited by the organization owner
 * (like completing organization details after an invitation from the administrator).
 */
class HeadquarterSetupController extends Controller
{
    public function __construct(private readonly HeadquarterServiceInterface $headquarters)
    {
        //
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($this->headquarters->hasHeadquarter($user)) {
            return redirect()->route($user->role->dashboardRoute());
        }

        $type = $this->headquarters->pendingSetupType($user);
        $organization = $user->organization;

        return Inertia::render($user->role->pageGroup().'/owner/setup', [
            'type' => $type->value,
            'email' => $user->email,
            'phone' => $user->phone,
            'organizationName' => $organization->name,
            'organizationNip' => $organization->NIP,
        ]);
    }

    public function store(StoreHeadquarterRequest $request): RedirectResponse
    {
        $user = $request->user();

        $this->headquarters->createForInvitee($user, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Siedziba została dodana.']);

        return redirect()->route($user->role->dashboardRoute());
    }

    /**
     * Company data from the MF register by NIP, to be injected into the form.
     */
    public function nipLookup(Request $request, NipServiceInterface $nipService): JsonResponse
    {
        $nip = $request->validate(['nip' => ['required', 'string', 'max:20']])['nip'];

        return response()->json($nipService->fetchByNip($nip));
    }
}
