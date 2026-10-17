<?php

namespace App\Http\Controllers\Platform\Admin;

use App\Http\Controllers\Controller;
use App\Enums\HeadquarterType;
use App\Services\Contracts\StructureListingServiceInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseHeadquarterController extends Controller
{
    public function index(Request $request, StructureListingServiceInterface $structure): Response
    {
        return Inertia::render('platform/admin/warehouses/headquarters/index', ['rows' => $structure->headquarters($request->user(), HeadquarterType::Warehouse)]);
    }

    /**
     * Tree of all warehouses headquarters with their organizations, branches and people.
     */
    public function tree(): Response
    {
        return Inertia::render('platform/admin/warehouses/my-headquarters/index');
    }
}
