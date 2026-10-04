<?php

namespace App\Http\Controllers\Platform;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/admin/index');
    }

    public function dashboard(): Response
    {
        return Inertia::render('platform/admin/dashboard/index', [
            'stats' => [
                'users' => User::count(),
                'products' => Product::count(),
                'teams' => Team::count(),
                'roles' => count(UserRole::cases()),
            ],
            'latestUsers' => User::query()
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->label(),
                    'barcode' => $user->barcode,
                ]),
        ]);
    }
}
