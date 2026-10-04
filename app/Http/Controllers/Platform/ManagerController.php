<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ManagerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/manager/index');
    }

    public function dashboard(): Response
    {
        return Inertia::render('platform/manager/dashboard/index');
    }
}
