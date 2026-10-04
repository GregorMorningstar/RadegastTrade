<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class OwnerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('shop/owner/index');
    }

    public function dashboard(): Response
    {
        return Inertia::render('shop/owner/dashboard/index');
    }
}
