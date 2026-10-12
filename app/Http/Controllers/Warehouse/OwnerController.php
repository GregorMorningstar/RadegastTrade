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
    public function index(Request $request): Response|RedirectResponse
    {
        return $this->withHeadquarter($request) ?? Inertia::render('platform/warehouses/owner/index');
    }

    public function dashboard(Request $request, HeadquarterServiceInterface $service): Response|RedirectResponse
    {
        return $this->withHeadquarter($request) ?? Inertia::render('platform/warehouses/owner/dashboard/index', $service->dashboardData($request->user()));
    }

    /**
     * An invited manager (an account with an organization) without a headquarters first fills in its details.
     */
    private function withHeadquarter(Request $request): ?RedirectResponse
    {
        $user = $request->user();

        return (! $user->organization_id || $user->warehouse_headquarter_id) ? null : to_route('warehouse.owner.setup');
    }
}
