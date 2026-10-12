<?php

use App\Http\Controllers\AcceptOrganizationInvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Platform;
use App\Http\Controllers\Shop;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Warehouse;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Dokumenty prawne (dostępne bez logowania, linkowane z rejestracji)
Route::inertia('regulamin', 'legal/terms')->name('legal.terms');
Route::inertia('rodo', 'legal/privacy')->name('legal.privacy');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

// Platforma: admin, manager, employee, właściciel organizacji
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

        // Właściciel organizacji (zapraszany przez administratora)
        Route::prefix('organization')->name('organization.')->middleware('role:organization_owner')->group(function () {
            Route::get('/', [Platform\OrganizationOwnerController::class, 'index'])->name('index');
            Route::get('dashboard', [Platform\OrganizationOwnerController::class, 'dashboard'])->name('dashboard');
            Route::get('nip-lookup', [Platform\OrganizationOwnerController::class, 'nipLookup'])
                ->middleware('throttle:20,1')
                ->name('nip-lookup');
            Route::get('setup', [Platform\OrganizationOwnerController::class, 'setup'])->name('setup');
            Route::post('setup', [Platform\OrganizationOwnerController::class, 'storeSetup'])->name('setup.store');

            // Na razie tylko widoki, bez backendu
            Route::inertia('shops', 'platform/organization/shops/index')->name('shops');
            Route::inertia('warehouses', 'platform/organization/warehouses/index')->name('warehouses');
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

// Link z maila: gość bez konta trafia na rejestrację (e-mail wypełniony z zaproszenia), gość z kontem
// na logowanie, a zalogowany widzi pole na klucz. Po rejestracji/logowaniu wraca na ten link.
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

require __DIR__.'/settings.php';

// Nieznane adresy też przechodzą przez grupę "web" (sesja, użytkownik), więc 404 wie, dokąd przekierować
Route::fallback(fn () => abort(404));
