<?php

namespace App\Http\Controllers\Platform\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseBranchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/admin/warehouses/branches/index');
    }
}
