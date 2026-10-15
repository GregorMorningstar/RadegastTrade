<?php

namespace App\Http\Controllers\Platform\Organization;

use App\Enums\HeadquarterType;
use App\Http\Controllers\Controller;
use App\Services\Contracts\HeadquarterManagerServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Managers of a headquarters, chosen from the people of the organization (organization owner panel).
 * A headquarters may have several managers (shift work).
 */
class HeadquarterManagerController extends Controller
{
    public function __construct(private readonly HeadquarterManagerServiceInterface $managers)
    {
        //
    }

    public function candidates(Request $request, HeadquarterType $type, int $headquarter): JsonResponse
    {
        return response()->json($this->managers->candidates($request->user(), $type, $headquarter));
    }

    public function assign(Request $request, HeadquarterType $type, int $headquarter): RedirectResponse
    {
        $this->managers->assign($request->user(), $type, $headquarter, $this->userId($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dodano kierownika siedziby.']);

        return back();
    }

    public function remove(Request $request, HeadquarterType $type, int $headquarter, int $user): RedirectResponse
    {
        $this->managers->remove($request->user(), $type, $headquarter, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kierownik został odłączony od siedziby.']);

        return back();
    }

    /**
     * Changes the manager into a worker of the same group (shop / warehouse).
     */
    public function demote(Request $request, HeadquarterType $type, int $headquarter, int $user): RedirectResponse
    {
        $request->validate(['role' => ['required', 'in:worker']]);

        $this->managers->demoteManager($request->user(), $type, $headquarter, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rola została zmieniona na pracownika.']);

        return back();
    }

    private function userId(Request $request): int
    {
        return (int) $request->validate(['user_id' => ['required', 'integer']])['user_id'];
    }
}
