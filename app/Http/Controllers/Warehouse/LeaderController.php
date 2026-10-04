<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class LeaderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('warehouse/leader/index');
    }

    public function dashboard(): Response
    {
        return Inertia::render('warehouse/leader/dashboard/index');
    }
}
