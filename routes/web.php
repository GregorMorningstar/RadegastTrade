<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Platform;
use App\Http\Controllers\Shop;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Warehouse;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

// Platforma: admin, manager, employee
Route::prefix('platform')
    ->name('platform.')
    ->middleware(['auth', 'verified'])
    ->group(function () {
        Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
            Route::get('/', [Platform\AdminController::class, 'index'])->name('index');
            Route::get('dashboard', [Platform\AdminController::class, 'dashboard'])->name('dashboard');
        });

        Route::prefix('manager')->name('manager.')->middleware('role:manager')->group(function () {
            Route::get('/', [Platform\ManagerController::class, 'index'])->name('index');
            Route::get('dashboard', [Platform\ManagerController::class, 'dashboard'])->name('dashboard');
        });

        Route::prefix('employee')->name('employee.')->middleware('role:employee')->group(function () {
            Route::get('/', [Platform\EmployeeController::class, 'index'])->name('index');
            Route::get('dashboard', [Platform\EmployeeController::class, 'dashboard'])->name('dashboard');
        });
    });

// Hurtownia: owner, leader, worker
Route::prefix('warehouse')
    ->name('warehouse.')
    ->middleware(['auth', 'verified'])
    ->group(function () {
        Route::prefix('owner')->name('owner.')->middleware('role:warehouse_owner')->group(function () {
            Route::get('/', [Warehouse\OwnerController::class, 'index'])->name('index');
            Route::get('dashboard', [Warehouse\OwnerController::class, 'dashboard'])->name('dashboard');
        });

        Route::prefix('leader')->name('leader.')->middleware('role:warehouse_leader')->group(function () {
            Route::get('/', [Warehouse\LeaderController::class, 'index'])->name('index');
            Route::get('dashboard', [Warehouse\LeaderController::class, 'dashboard'])->name('dashboard');
        });

        Route::prefix('worker')->name('worker.')->middleware('role:warehouse_worker')->group(function () {
            Route::get('/', [Warehouse\WorkerController::class, 'index'])->name('index');
            Route::get('dashboard', [Warehouse\WorkerController::class, 'dashboard'])->name('dashboard');
        });
    });

// Sklep: owner, leader, worker
Route::prefix('shop')
    ->name('shop.')
    ->middleware(['auth', 'verified'])
    ->group(function () {
        Route::prefix('owner')->name('owner.')->middleware('role:shop_owner')->group(function () {
            Route::get('/', [Shop\OwnerController::class, 'index'])->name('index');
            Route::get('dashboard', [Shop\OwnerController::class, 'dashboard'])->name('dashboard');
        });

        Route::prefix('leader')->name('leader.')->middleware('role:shop_leader')->group(function () {
            Route::get('/', [Shop\LeaderController::class, 'index'])->name('index');
            Route::get('dashboard', [Shop\LeaderController::class, 'dashboard'])->name('dashboard');
        });

        Route::prefix('worker')->name('worker.')->middleware('role:shop_worker')->group(function () {
            Route::get('/', [Shop\WorkerController::class, 'index'])->name('index');
            Route::get('dashboard', [Shop\WorkerController::class, 'dashboard'])->name('dashboard');
        });
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';

// Nieznane adresy też przechodzą przez grupę "web" (sesja, użytkownik), więc 404 wie, dokąd przekierować
Route::fallback(fn () => abort(404));
