<?php

namespace App\Http\Controllers;

use App\Enums\HeadquarterType;
use App\Http\Requests\Platform\StoreHeadquarterRequest;
use App\Services\Contracts\BranchServiceInterface;
use App\Services\Contracts\NipServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Completing branch details by a leader invited by the shop or warehouse headquarters manager.
 */
class BranchSetupController extends Controller
{
    public function __construct(private readonly BranchServiceInterface $branches)
    {
        //
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $type = HeadquarterType::forLeaderRole($user->role);

        if ($type && $user->{$type->branchColumn()}) {
            return redirect()->route($user->role->dashboardRoute());
        }

        $type = $this->branches->pendingSetupType($user);
        $headquarter = $type === HeadquarterType::Shop ? $user->shopHeadquarter : $user->warehouseHeadquarter;

        return Inertia::render($type->pageGroup().'/leader/setup', [
            'email' => $user->email,
            'phone' => $user->phone,
            'organizationName' => $user->organization->name,
            'headquarterName' => $headquarter->name,
            'organizationNip' => $headquarter->effectiveNip(),
        ]);
    }

    public function store(StoreHeadquarterRequest $request): RedirectResponse
    {
        $user = $request->user();

        $this->branches->createForInvitee($user, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Placówka została dodana.']);

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
