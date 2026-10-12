<?php

namespace App\Http\Controllers\Platform\Admin;

use App\Enums\HeadquarterType;
use App\Http\Controllers\Controller;
use App\Services\Contracts\StructureListingServiceInterface;
use Inertia\Inertia;
use Inertia\Response;

class ShopBranchController extends Controller
{
    public function index(StructureListingServiceInterface $structure): Response
    {
        return Inertia::render('platform/admin/shops/branches/index', ['rows' => $structure->branches(HeadquarterType::Shop)]);
    }
}
