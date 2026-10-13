<?php

namespace App\Http\Controllers\Platform\Admin;

use App\Http\Controllers\Controller;
use App\Enums\HeadquarterType;
use App\Services\Contracts\StructureListingServiceInterface;
use Inertia\Inertia;
use Inertia\Response;

class ShopHeadquarterController extends Controller
{
    public function index(StructureListingServiceInterface $structure): Response
    {
        return Inertia::render('platform/admin/shops/headquarters/index', ['rows' => $structure->headquarters(HeadquarterType::Shop)]);
    }

    /**
     * Tree of all shops headquarters with their organizations, branches and people.
     */
    public function tree(): Response
    {
        return Inertia::render('platform/admin/shops/my-headquarters/index');
    }
}
