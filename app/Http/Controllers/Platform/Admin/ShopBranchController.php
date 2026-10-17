<?php

namespace App\Http\Controllers\Platform\Admin;

use App\Enums\HeadquarterType;
use App\Http\Controllers\Controller;
use App\Services\Contracts\StructureListingServiceInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShopBranchController extends Controller
{
    public function index(Request $request, StructureListingServiceInterface $structure): Response
    {
        return Inertia::render('platform/admin/shops/branches/index', ['rows' => $structure->branches($request->user(), HeadquarterType::Shop)]);
    }
}
