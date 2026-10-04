<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class OwnerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('warehouse/owner/index');
    }

    public function dashboard(): Response
    {
        return Inertia::render('warehouse/owner/dashboard/index');
    }
}
