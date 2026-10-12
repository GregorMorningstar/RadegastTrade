<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Services\Contracts\BranchServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaderController extends Controller
{
    public function __construct(private readonly BranchServiceInterface $branches)
    {
        //
    }

    public function index(Request $request): Response|RedirectResponse
    {
        return $this->withBranch($request) ?? Inertia::render('platform/warehouses/leader/index');
    }

    public function dashboard(Request $request): Response|RedirectResponse
    {
        return $this->withBranch($request) ?? Inertia::render('platform/warehouses/leader/dashboard/index', $this->branches->dashboardData($request->user()));
    }

    /**
     * An invited branch leader (an account with an organization and headquarters) first fills in the branch details.
     */
    private function withBranch(Request $request): ?RedirectResponse
    {
        return $this->branches->needsSetup($request->user()) ? to_route('warehouse.leader.setup') : null;
    }
}
