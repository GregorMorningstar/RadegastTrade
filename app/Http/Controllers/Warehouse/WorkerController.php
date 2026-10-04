<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class WorkerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('warehouse/worker/index');
    }

    public function dashboard(): Response
    {
        return Inertia::render('warehouse/worker/dashboard/index');
    }
}
