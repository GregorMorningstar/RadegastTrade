<?php

namespace App\Http\Controllers;

use App\Enums\HeadquarterType;
use App\Services\Contracts\HeadquarterManagerServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Leaders of a branch, chosen from the people of the organization. A branch may have several leaders
 * (shift work). Used by the headquarters manager (own branches) and by the organization owner.
 */
class BranchLeaderController extends Controller
{
    public function __construct(private readonly HeadquarterManagerServiceInterface $managers)
    {
        //
    }

    public function candidates(Request $request): JsonResponse
    {
        return response()->json($this->managers->branchCandidates($request->user(), $this->type($request), $this->branch($request)));
    }

    public function assign(Request $request): RedirectResponse
    {
        $userId = (int) $request->validate(['user_id' => ['required', 'integer']])['user_id'];

        $this->managers->assignBranchLeader($request->user(), $this->type($request), $this->branch($request), $userId);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dodano kierownika placówki.']);

        return back();
    }

    public function remove(Request $request): RedirectResponse
    {
        $this->managers->removeBranchLeader($request->user(), $this->type($request), $this->branch($request), (int) $request->route('user'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kierownik został odłączony od placówki.']);

        return back();
    }

    /**
     * Changes the leader into a worker of the same group who stays in the branch.
     */
    public function demote(Request $request): RedirectResponse
    {
        $request->validate(['role' => ['required', 'in:worker']]);

        $this->managers->demoteBranchLeader($request->user(), $this->type($request), $this->branch($request), (int) $request->route('user'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rola została zmieniona na pracownika.']);

        return back();
    }

    /**
     * The organization owner's routes carry the type in the URL ("shops" / "warehouses"); the headquarters manager's
     * routes do not, the type follows from the role (a shop manager works with shop branches).
     */
    private function type(Request $request): HeadquarterType
    {
        // without the parameter in the method signature the URL segment is still the plain string
        $fromUrl = HeadquarterType::tryFrom((string) $request->route('type'));

        if ($fromUrl !== null) {
            return $fromUrl;
        }

        $fromRole = HeadquarterType::forRole($request->user()->role);
        abort_if($fromRole === null, 403);

        return $fromRole;
    }

    private function branch(Request $request): int
    {
        return (int) $request->route('branch');
    }
}
