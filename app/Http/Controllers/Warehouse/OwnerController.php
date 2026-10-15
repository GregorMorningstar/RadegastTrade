<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Services\Contracts\HeadquarterServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OwnerController extends Controller
{
    public function index(Request $request, HeadquarterServiceInterface $service): Response|RedirectResponse
    {
        return $this->withHeadquarter($request, $service) ?? Inertia::render('platform/warehouses/owner/index');
    }

    public function dashboard(Request $request, HeadquarterServiceInterface $service): Response|RedirectResponse
    {
        return $this->withHeadquarter($request, $service) ?? Inertia::render('platform/warehouses/owner/dashboard/index', $service->dashboardData($request->user()));
    }

    /**
     * An invited manager (an account with an organization) without a headquarters first fills in its details.
     */
    private function withHeadquarter(Request $request, HeadquarterServiceInterface $service): ?RedirectResponse
    {
        return $service->needsSetup($request->user()) ? to_route('warehouse.owner.setup') : null;
    }
}
