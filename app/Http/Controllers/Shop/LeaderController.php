<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class LeaderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('shop/leader/index');
    }

    public function dashboard(): Response
    {
        return Inertia::render('shop/leader/dashboard/index');
    }
}
