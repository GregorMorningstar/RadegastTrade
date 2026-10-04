<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class WorkerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('shop/worker/index');
    }

    public function dashboard(): Response
    {
        return Inertia::render('shop/worker/dashboard/index');
    }
}
