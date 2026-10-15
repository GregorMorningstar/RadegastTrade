<?php

namespace App\Http\Controllers\Shop;

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
        return $this->withHeadquarter($request, $service) ?? Inertia::render('platform/shop/owner/index');
    }

    public function dashboard(Request $request, HeadquarterServiceInterface $service): Response|RedirectResponse
    {
        return $this->withHeadquarter($request, $service) ?? Inertia::render('platform/shop/owner/dashboard/index', $service->dashboardData($request->user()));
    }

    /**
     * An invited manager (an account with an organization) without a headquarters first fills in its details.
     */
    private function withHeadquarter(Request $request, HeadquarterServiceInterface $service): ?RedirectResponse
    {
        return $service->needsSetup($request->user()) ? to_route('shop.owner.setup') : null;
    }
}
