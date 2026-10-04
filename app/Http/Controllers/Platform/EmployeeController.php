<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/employee/index');
    }

    public function dashboard(): Response
    {
        return Inertia::render('platform/employee/dashboard/index');
    }
}
