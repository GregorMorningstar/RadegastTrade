<?php

namespace App\Http\Controllers\Platform\Admin;

use App\Http\Controllers\Controller;
use App\Enums\HeadquarterType;
use App\Services\Contracts\StructureListingServiceInterface;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseHeadquarterController extends Controller
{
    public function index(StructureListingServiceInterface $structure): Response
    {
        return Inertia::render('platform/admin/warehouses/headquarters/index', ['rows' => $structure->headquarters(HeadquarterType::Warehouse)]);
    }

    /**
     * Tree of all warehouses headquarters with their organizations, branches and people.
     */
    public function tree(): Response
    {
        return Inertia::render('platform/admin/warehouses/my-headquarters/index');
    }
}
