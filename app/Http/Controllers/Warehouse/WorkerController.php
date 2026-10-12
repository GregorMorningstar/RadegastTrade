<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Services\Contracts\BranchServiceInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/warehouses/worker/index');
    }

    public function dashboard(Request $request, BranchServiceInterface $branches): Response
    {
        return Inertia::render('platform/warehouses/worker/dashboard/index', $branches->dashboardData($request->user()));
    }
}
