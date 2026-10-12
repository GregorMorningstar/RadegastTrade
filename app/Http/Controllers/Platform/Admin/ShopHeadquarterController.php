<?php

namespace App\Http\Controllers\Platform\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ShopHeadquarterController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/admin/shops/headquarters/index');
    }
}
