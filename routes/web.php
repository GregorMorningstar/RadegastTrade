<?php

use App\Http\Controllers\AcceptOrganizationInvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\BranchSetupController;
use App\Http\Controllers\BranchWorkerController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\HeadquarterBranchController;
use App\Http\Controllers\HeadquarterSetupController;
use App\Http\Controllers\TeamMembersController;
use App\Http\Controllers\Platform;
use App\Http\Controllers\Shop;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Warehouse;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Legal documents (available without logging in, linked from registration)
Route::inertia('regulamin', 'legal/terms')->name('legal.terms');
Route::inertia('rodo', 'legal/privacy')->name('legal.privacy');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

// Platform: admin, manager, employee, organization owner
Route::prefix('platform')
    ->name('platform.')
    ->middleware(['auth', 'verified'])
    ->group(function () {
        Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
            Route::get('/', [Platform\AdminController::class, 'index'])->name('index');
            Route::get('dashboard', [Platform\AdminController::class, 'dashboard'])->name('dashboard');

            Route::get('organizations', [Platform\Admin\OrganizationController::class, 'index'])->name('organizations.index');
            Route::get('organizations/create', [Platform\Admin\OrganizationController::class, 'create'])->name('organizations.create');
            Route::post('organizations/invitations', [Platform\Admin\OrganizationInvitationController::class, 'storeNew'])
                ->middleware('throttle:20,1')
                ->name('organizations.invitations.store-new');
            Route::post('organizations/{organization}/invitations', [Platform\Admin\OrganizationInvitationController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('organizations.invitations.store');
            Route::delete('organizations/invitations/{invitation:id}', [Platform\Admin\OrganizationInvitationController::class, 'destroy'])
                ->name('organizations.invitations.destroy');

            Route::prefix('warehouses')->name('warehouses.')->group(function () {
                Route::get('headquarters', [Platform\Admin\WarehouseHeadquarterController::class, 'index'])->name('headquarters.index');
                Route::get('branches', [Platform\Admin\WarehouseBranchController::class, 'index'])->name('branches.index');
            });

            Route::prefix('shops')->name('shops.')->group(function () {
                Route::get('headquarters', [Platform\Admin\ShopHeadquarterController::class, 'index'])->name('headquarters.index');
                Route::get('branches', [Platform\Admin\ShopBranchController::class, 'index'])->name('branches.index');
            });
        });

        Route::prefix('manager')->name('manager.')->middleware('role:manager')->group(function () {
            Route::get('/', [Platform\ManagerController::class, 'index'])->name('index');
            Route::get('dashboard', [Platform\ManagerController::class, 'dashboard'])->name('dashboard');
        });

        Route::prefix('employee')->name('employee.')->middleware('role:employee')->group(function () {
            Route::get('/', [Platform\EmployeeController::class, 'index'])->name('index');
            Route::get('dashboard', [Platform\EmployeeController::class, 'dashboard'])->name('dashboard');
        });

        // Organization owner (invited by the administrator)
        Route::prefix('organization')->name('organization.')->middleware('role:organization_owner')->group(function () {
            Route::get('/', [Platform\OrganizationOwnerController::class, 'index'])->name('index');
            Route::get('dashboard', [Platform\OrganizationOwnerController::class, 'dashboard'])->name('dashboard');
            Route::get('nip-lookup', [Platform\OrganizationOwnerController::class, 'nipLookup'])
                ->middleware('throttle:20,1')
                ->name('nip-lookup');
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('setup', [Platform\OrganizationOwnerController::class, 'setup'])->name('setup');
            Route::post('setup', [Platform\OrganizationOwnerController::class, 'storeSetup'])->name('setup.store');

            // Shop (shops) and warehouse (warehouses) headquarters and the invitations of their managers
            Route::get('{type}', [Platform\Organization\HeadquarterController::class, 'index'])
                ->whereIn('type', ['shops', 'warehouses'])
                ->name('headquarters.index');
            Route::post('{type}/invitations', [Platform\Organization\HeadquarterController::class, 'inviteNew'])
                ->whereIn('type', ['shops', 'warehouses'])
                ->middleware('throttle:20,1')
                ->name('headquarters.invitations.store-new');
            Route::post('{type}/{headquarter}/invitations', [Platform\Organization\HeadquarterController::class, 'invite'])
                ->whereIn('type', ['shops', 'warehouses'])
                ->whereNumber('headquarter')
                ->middleware('throttle:20,1')
                ->name('headquarters.invitations.store');
            Route::delete('invitations/{invitation:id}', [Platform\Organization\HeadquarterController::class, 'cancelInvitation'])
                ->name('invitations.destroy');
        });
    });

// Hurtownia: owner, leader, worker
Route::prefix('warehouse')
    ->name('warehouse.')
    ->middleware(['auth', 'verified'])
    ->group(function () {
        Route::prefix('owner')->name('owner.')->middleware('role:warehouse_owner')->group(function () {
            Route::get('setup', [HeadquarterSetupController::class, 'show'])->name('setup');
            Route::post('setup', [HeadquarterSetupController::class, 'store'])->name('setup.store');
            Route::get('nip-lookup', [HeadquarterSetupController::class, 'nipLookup'])->middleware('throttle:20,1')->name('nip-lookup');
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('branches', [HeadquarterBranchController::class, 'index'])->name('branches.index');
            Route::post('branches/invitations', [HeadquarterBranchController::class, 'invite'])
                ->middleware('throttle:20,1')
                ->name('branches.invitations.store');
            Route::delete('branches/invitations/{invitation:id}', [HeadquarterBranchController::class, 'cancelInvitation'])
                ->name('branches.invitations.destroy');
            Route::get('/', [Warehouse\OwnerController::class, 'index'])->name('index');
            Route::get('dashboard', [Warehouse\OwnerController::class, 'dashboard'])->name('dashboard');
        });

        Route::prefix('leader')->name('leader.')->middleware('role:warehouse_leader')->group(function () {
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('workers', [BranchWorkerController::class, 'index'])->name('workers.index');
            Route::post('workers/invitations', [BranchWorkerController::class, 'invite'])
                ->middleware('throttle:20,1')
                ->name('workers.invitations.store');
            Route::delete('workers/invitations/{invitation:id}', [BranchWorkerController::class, 'cancelInvitation'])
                ->name('workers.invitations.destroy');
            Route::get('setup', [BranchSetupController::class, 'show'])->name('setup');
            Route::post('setup', [BranchSetupController::class, 'store'])->name('setup.store');
            Route::get('nip-lookup', [BranchSetupController::class, 'nipLookup'])->middleware('throttle:20,1')->name('nip-lookup');
            Route::get('/', [Warehouse\LeaderController::class, 'index'])->name('index');
            Route::get('dashboard', [Warehouse\LeaderController::class, 'dashboard'])->name('dashboard');
        });

        Route::prefix('worker')->name('worker.')->middleware('role:warehouse_worker')->group(function () {
            Route::get('team', TeamMembersController::class)->name('team');
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
            Route::get('setup', [HeadquarterSetupController::class, 'show'])->name('setup');
            Route::post('setup', [HeadquarterSetupController::class, 'store'])->name('setup.store');
            Route::get('nip-lookup', [HeadquarterSetupController::class, 'nipLookup'])->middleware('throttle:20,1')->name('nip-lookup');
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('branches', [HeadquarterBranchController::class, 'index'])->name('branches.index');
            Route::post('branches/invitations', [HeadquarterBranchController::class, 'invite'])
                ->middleware('throttle:20,1')
                ->name('branches.invitations.store');
            Route::delete('branches/invitations/{invitation:id}', [HeadquarterBranchController::class, 'cancelInvitation'])
                ->name('branches.invitations.destroy');
            Route::get('/', [Shop\OwnerController::class, 'index'])->name('index');
            Route::get('dashboard', [Shop\OwnerController::class, 'dashboard'])->name('dashboard');
        });

        Route::prefix('leader')->name('leader.')->middleware('role:shop_leader')->group(function () {
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('workers', [BranchWorkerController::class, 'index'])->name('workers.index');
            Route::post('workers/invitations', [BranchWorkerController::class, 'invite'])
                ->middleware('throttle:20,1')
                ->name('workers.invitations.store');
            Route::delete('workers/invitations/{invitation:id}', [BranchWorkerController::class, 'cancelInvitation'])
                ->name('workers.invitations.destroy');
            Route::get('setup', [BranchSetupController::class, 'show'])->name('setup');
            Route::post('setup', [BranchSetupController::class, 'store'])->name('setup.store');
            Route::get('nip-lookup', [BranchSetupController::class, 'nipLookup'])->middleware('throttle:20,1')->name('nip-lookup');
            Route::get('/', [Shop\LeaderController::class, 'index'])->name('index');
            Route::get('dashboard', [Shop\LeaderController::class, 'dashboard'])->name('dashboard');
        });

        Route::prefix('worker')->name('worker.')->middleware('role:shop_worker')->group(function () {
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('/', [Shop\WorkerController::class, 'index'])->name('index');
            Route::get('dashboard', [Shop\WorkerController::class, 'dashboard'])->name('dashboard');
        });
    });

// Link from the email: a guest without an account lands on registration (email prefilled from the invitation), a guest with an account
// lands on login, and a logged-in user sees the key field. After registering/logging in they return to this link.
Route::get('organization-invitations/{invitation}', [AcceptOrganizationInvitationController::class, 'show'])
    ->name('organization-invitations.show');

Route::post('organization-invitations/{invitation}/resend', [AcceptOrganizationInvitationController::class, 'resend'])
    ->middleware('throttle:5,10')
    ->name('organization-invitations.resend');

Route::middleware(['auth'])->group(function () {
    Route::post('organization-invitations/{invitation}', [AcceptOrganizationInvitationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('organization-invitations.store');

    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

// Chat: only people from the same organization (checked by ChatService)
Route::middleware(['auth', 'verified'])->prefix('chat')->name('chat.')->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('index');
    Route::get('contacts', [ChatController::class, 'contacts'])->name('contacts');
    Route::get('{user}/messages', [ChatController::class, 'messages'])->whereNumber('user')->name('messages');
    Route::post('{user}/typing', [ChatController::class, 'typing'])
        ->whereNumber('user')
        ->middleware('throttle:120,1')
        ->name('typing');
    Route::post('{user}/messages', [ChatController::class, 'send'])
        ->whereNumber('user')
        ->middleware('throttle:60,1')
        ->name('send');
});

require __DIR__.'/settings.php';

// Unknown URLs also go through the "web" group (session, user), so the 404 knows where to redirect
Route::fallback(fn () => abort(404));
