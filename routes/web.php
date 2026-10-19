<?php

use App\Http\Controllers\AcceptOrganizationInvitationController;
use App\Http\Controllers\AccountInactiveController;
use App\Http\Controllers\AccountPendingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\BranchLeaderController;
use App\Http\Controllers\BranchSetupController;
use App\Http\Controllers\BranchWorkerController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\HeadquarterBranchController;
use App\Http\Controllers\HeadquarterSetupController;
use App\Http\Controllers\HeadquarterStructureController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamMembersController;
use App\Http\Controllers\UnitMembershipController;
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
        // Preview of the people in an organization's shops and warehouses (administrator and manager)
        Route::get('organizations/{organization}/members', Platform\OrganizationMembersController::class)
            ->middleware(['role:admin,manager,employee', 'throttle:120,1,w1'])
            ->name('organizations.members');

        // Tree of all shop or warehouse headquarters (administrator, manager and employee)
        Route::get('headquarters/{type}/tree', Platform\HeadquartersTreeController::class)
            ->whereIn('type', ['shops', 'warehouses'])
            ->middleware(['role:admin,manager,employee', 'throttle:120,1,w2'])
            ->name('headquarters.tree');

        Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
            Route::get('/', [Platform\AdminController::class, 'index'])->name('index');
            Route::get('dashboard', [Platform\AdminController::class, 'dashboard'])->name('dashboard');
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');

            Route::get('organizations', [Platform\Admin\OrganizationController::class, 'index'])->name('organizations.index');
            Route::post('organizations/invitations', [Platform\Admin\OrganizationInvitationController::class, 'storeNew'])
                ->middleware('throttle:20,1,w3')
                ->name('organizations.invitations.store-new');
            Route::delete('organizations/invitations/{invitation:id}', [Platform\Admin\OrganizationInvitationController::class, 'destroy'])
                ->name('organizations.invitations.destroy');

            Route::prefix('warehouses')->name('warehouses.')->group(function () {
                Route::get('headquarters', [Platform\Admin\WarehouseHeadquarterController::class, 'index'])->name('headquarters.index');
                Route::get('my-headquarters', [Platform\Admin\WarehouseHeadquarterController::class, 'tree'])->name('headquarters.tree');
                Route::get('branches', [Platform\Admin\WarehouseBranchController::class, 'index'])->name('branches.index');
            });

            Route::prefix('shops')->name('shops.')->group(function () {
                Route::get('headquarters', [Platform\Admin\ShopHeadquarterController::class, 'index'])->name('headquarters.index');
                Route::get('my-headquarters', [Platform\Admin\ShopHeadquarterController::class, 'tree'])->name('headquarters.tree');
                Route::get('branches', [Platform\Admin\ShopBranchController::class, 'index'])->name('branches.index');
            });
        });

        // Organization owner (invited by the administrator)
        Route::prefix('organization')->name('organization.')->middleware('role:organization_owner')->group(function () {
            Route::get('/', [Platform\OrganizationOwnerController::class, 'index'])->name('index');
            Route::get('dashboard', [Platform\OrganizationOwnerController::class, 'dashboard'])->name('dashboard');
            Route::get('people/{user}', [Platform\OrganizationOwnerController::class, 'person'])
                ->whereNumber('user')
                ->middleware('throttle:60,1,w48')
                ->name('people.show');
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('products', [ProductController::class, 'index'])->middleware('throttle:120,1,w4')->name('products.index');
            Route::get('nip-lookup', [Platform\OrganizationOwnerController::class, 'nipLookup'])
                ->middleware('throttle:20,1,w5')
                ->name('nip-lookup');
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('setup', [Platform\OrganizationOwnerController::class, 'setup'])->name('setup');
            Route::post('setup', [Platform\OrganizationOwnerController::class, 'storeSetup'])->name('setup.store');

            // Shop (shops) and warehouse (warehouses) headquarters and the invitations of their managers
            Route::get('{type}/{headquarter}/manager-candidates', [Platform\Organization\HeadquarterManagerController::class, 'candidates'])
                ->whereIn('type', ['shops', 'warehouses'])
                ->whereNumber('headquarter')
                ->middleware('throttle:120,1,w6')
                ->name('headquarters.manager.candidates');
            Route::post('{type}/{headquarter}/manager', [Platform\Organization\HeadquarterManagerController::class, 'assign'])
                ->whereIn('type', ['shops', 'warehouses'])
                ->whereNumber('headquarter')
                ->middleware('throttle:20,1,w7')
                ->name('headquarters.manager.assign');
            Route::delete('{type}/{headquarter}/manager/{user}', [Platform\Organization\HeadquarterManagerController::class, 'remove'])
                ->whereIn('type', ['shops', 'warehouses'])
                ->whereNumber(['headquarter', 'user'])
                ->middleware('throttle:20,1,w8')
                ->name('headquarters.manager.remove');
            Route::patch('{type}/{headquarter}/manager/{user}/role', [Platform\Organization\HeadquarterManagerController::class, 'demote'])
                ->whereIn('type', ['shops', 'warehouses'])
                ->whereNumber(['headquarter', 'user'])
                ->middleware('throttle:20,1,w9')
                ->name('headquarters.manager.demote');
            // Branches of the shops / warehouses: the owner invites the leader of a new branch in any headquarters
            Route::get('{type}/branches', [Platform\Organization\BranchController::class, 'index'])
                ->whereIn('type', ['shops', 'warehouses'])
                ->name('branches.index');
            Route::post('{type}/{headquarter}/branches/invitations', [Platform\Organization\BranchController::class, 'invite'])
                ->whereIn('type', ['shops', 'warehouses'])
                ->whereNumber('headquarter')
                ->middleware('throttle:20,1,w10')
                ->name('branches.invitations.store');
            Route::get('{type}/tree', Platform\Organization\HeadquartersTreeController::class)
                ->whereIn('type', ['shops', 'warehouses'])
                ->middleware('throttle:120,1,w11')
                ->name('headquarters.tree');
            Route::get('{type}', [Platform\Organization\HeadquarterController::class, 'index'])
                ->whereIn('type', ['shops', 'warehouses'])
                ->name('headquarters.index');
            Route::post('{type}/invitations', [Platform\Organization\HeadquarterController::class, 'inviteNew'])
                ->whereIn('type', ['shops', 'warehouses'])
                ->middleware('throttle:20,1,w12')
                ->name('headquarters.invitations.store-new');
            Route::post('{type}/{headquarter}/invitations', [Platform\Organization\HeadquarterController::class, 'invite'])
                ->whereIn('type', ['shops', 'warehouses'])
                ->whereNumber('headquarter')
                ->middleware('throttle:20,1,w13')
                ->name('headquarters.invitations.store');
            Route::delete('invitations/{invitation:id}', [Platform\Organization\HeadquarterController::class, 'cancelInvitation'])
                ->name('invitations.destroy');
        });
    });

// Tasks (calendar, Gantt chart): the JSON of the task module of every role with a panel; who sees what and who may
// change what is decided by TaskService
Route::prefix('tasks')
    ->name('tasks.')
    ->middleware(['auth', 'verified'])
    ->group(function () {
        Route::get('data', [TaskController::class, 'data'])->middleware('throttle:120,1,w14')->name('data');
        Route::post('/', [TaskController::class, 'store'])->middleware('throttle:60,1,w15')->name('store');
        Route::patch('{task}', [TaskController::class, 'update'])->whereNumber('task')->middleware('throttle:120,1,w16')->name('update');
        Route::patch('{task}/schedule', [TaskController::class, 'reschedule'])->whereNumber('task')->middleware('throttle:240,1,w17')->name('schedule');
        Route::delete('{task}', [TaskController::class, 'destroy'])->whereNumber('task')->middleware('throttle:60,1,w18')->name('destroy');
    });

// Switching a person's account on or off (the organization owner of their organization)
Route::patch('memberships/people/{user}/active', [UnitMembershipController::class, 'active'])
    ->middleware(['auth', 'verified', 'throttle:30,1,w19'])
    ->whereNumber('user')
    ->name('memberships.active');

// Hurtownia: owner, leader, worker
Route::prefix('warehouse')
    ->name('warehouse.')
    ->middleware(['auth', 'verified'])
    ->group(function () {
        Route::prefix('owner')->name('owner.')->middleware('role:warehouse_owner')->group(function () {
            Route::get('setup', [HeadquarterSetupController::class, 'show'])->name('setup');
            Route::post('setup', [HeadquarterSetupController::class, 'store'])->name('setup.store');
            Route::get('nip-lookup', [HeadquarterSetupController::class, 'nipLookup'])->middleware('throttle:20,1,w20')->name('nip-lookup');
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('branches/{branch}/leaders/candidates', [BranchLeaderController::class, 'candidates'])
                ->whereNumber('branch')->middleware('throttle:120,1,w21')->name('branches.leaders.candidates');
            Route::post('branches/{branch}/leaders', [BranchLeaderController::class, 'assign'])
                ->whereNumber('branch')->middleware('throttle:20,1,w22')->name('branches.leaders.assign');
            Route::delete('branches/{branch}/leaders/{user}', [BranchLeaderController::class, 'remove'])
                ->whereNumber(['branch', 'user'])->middleware('throttle:20,1,w23')->name('branches.leaders.remove');
            Route::patch('branches/{branch}/leaders/{user}/role', [BranchLeaderController::class, 'demote'])
                ->whereNumber(['branch', 'user'])->middleware('throttle:20,1,w24')->name('branches.leaders.demote');
            Route::get('structure', [HeadquarterStructureController::class, 'show'])->name('structure');
            Route::get('structure/data', [HeadquarterStructureController::class, 'data'])->middleware('throttle:120,1,w25')->name('structure.data');
            Route::get('branches', [HeadquarterBranchController::class, 'index'])->name('branches.index');
            Route::post('branches/invitations', [HeadquarterBranchController::class, 'invite'])
                ->middleware('throttle:20,1,w26')
                ->name('branches.invitations.store');
            Route::delete('branches/invitations/{invitation:id}', [HeadquarterBranchController::class, 'cancelInvitation'])
                ->name('branches.invitations.destroy');
            Route::get('/', [Warehouse\OwnerController::class, 'index'])->name('index');
            Route::get('dashboard', [Warehouse\OwnerController::class, 'dashboard'])->name('dashboard');
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('products', [ProductController::class, 'index'])->middleware('throttle:120,1,w27')->name('products.index');
        });

        Route::prefix('leader')->name('leader.')->middleware('role:warehouse_leader')->group(function () {
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('workers', [BranchWorkerController::class, 'index'])->name('workers.index');
            Route::post('workers/invitations', [BranchWorkerController::class, 'invite'])
                ->middleware('throttle:20,1,w28')
                ->name('workers.invitations.store');
            Route::delete('workers/invitations/{invitation:id}', [BranchWorkerController::class, 'cancelInvitation'])
                ->name('workers.invitations.destroy');
            Route::get('setup', [BranchSetupController::class, 'show'])->name('setup');
            Route::post('setup', [BranchSetupController::class, 'store'])->name('setup.store');
            Route::get('nip-lookup', [BranchSetupController::class, 'nipLookup'])->middleware('throttle:20,1,w29')->name('nip-lookup');
            Route::get('/', [Warehouse\LeaderController::class, 'index'])->name('index');
            Route::get('dashboard', [Warehouse\LeaderController::class, 'dashboard'])->name('dashboard');
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('products', [ProductController::class, 'index'])->middleware('throttle:120,1,w30')->name('products.index');
        });

        Route::prefix('worker')->name('worker.')->middleware('role:warehouse_worker')->group(function () {
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('/', [Warehouse\WorkerController::class, 'index'])->name('index');
            Route::get('dashboard', [Warehouse\WorkerController::class, 'dashboard'])->name('dashboard');
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('products', [ProductController::class, 'index'])->middleware('throttle:120,1,w31')->name('products.index');
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
            Route::get('nip-lookup', [HeadquarterSetupController::class, 'nipLookup'])->middleware('throttle:20,1,w32')->name('nip-lookup');
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('branches/{branch}/leaders/candidates', [BranchLeaderController::class, 'candidates'])
                ->whereNumber('branch')->middleware('throttle:120,1,w33')->name('branches.leaders.candidates');
            Route::post('branches/{branch}/leaders', [BranchLeaderController::class, 'assign'])
                ->whereNumber('branch')->middleware('throttle:20,1,w34')->name('branches.leaders.assign');
            Route::delete('branches/{branch}/leaders/{user}', [BranchLeaderController::class, 'remove'])
                ->whereNumber(['branch', 'user'])->middleware('throttle:20,1,w35')->name('branches.leaders.remove');
            Route::patch('branches/{branch}/leaders/{user}/role', [BranchLeaderController::class, 'demote'])
                ->whereNumber(['branch', 'user'])->middleware('throttle:20,1,w36')->name('branches.leaders.demote');
            Route::get('structure', [HeadquarterStructureController::class, 'show'])->name('structure');
            Route::get('structure/data', [HeadquarterStructureController::class, 'data'])->middleware('throttle:120,1,w37')->name('structure.data');
            Route::get('branches', [HeadquarterBranchController::class, 'index'])->name('branches.index');
            Route::post('branches/invitations', [HeadquarterBranchController::class, 'invite'])
                ->middleware('throttle:20,1,w38')
                ->name('branches.invitations.store');
            Route::delete('branches/invitations/{invitation:id}', [HeadquarterBranchController::class, 'cancelInvitation'])
                ->name('branches.invitations.destroy');
            Route::get('/', [Shop\OwnerController::class, 'index'])->name('index');
            Route::get('dashboard', [Shop\OwnerController::class, 'dashboard'])->name('dashboard');
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('products', [ProductController::class, 'index'])->middleware('throttle:120,1,w39')->name('products.index');
        });

        Route::prefix('leader')->name('leader.')->middleware('role:shop_leader')->group(function () {
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('workers', [BranchWorkerController::class, 'index'])->name('workers.index');
            Route::post('workers/invitations', [BranchWorkerController::class, 'invite'])
                ->middleware('throttle:20,1,w40')
                ->name('workers.invitations.store');
            Route::delete('workers/invitations/{invitation:id}', [BranchWorkerController::class, 'cancelInvitation'])
                ->name('workers.invitations.destroy');
            Route::get('setup', [BranchSetupController::class, 'show'])->name('setup');
            Route::post('setup', [BranchSetupController::class, 'store'])->name('setup.store');
            Route::get('nip-lookup', [BranchSetupController::class, 'nipLookup'])->middleware('throttle:20,1,w41')->name('nip-lookup');
            Route::get('/', [Shop\LeaderController::class, 'index'])->name('index');
            Route::get('dashboard', [Shop\LeaderController::class, 'dashboard'])->name('dashboard');
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('products', [ProductController::class, 'index'])->middleware('throttle:120,1,w42')->name('products.index');
        });

        Route::prefix('worker')->name('worker.')->middleware('role:shop_worker')->group(function () {
            Route::get('team', TeamMembersController::class)->name('team');
            Route::get('/', [Shop\WorkerController::class, 'index'])->name('index');
            Route::get('dashboard', [Shop\WorkerController::class, 'dashboard'])->name('dashboard');
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('products', [ProductController::class, 'index'])->middleware('throttle:120,1,w43')->name('products.index');
        });
    });

// Link from the email: a guest without an account lands on registration (email prefilled from the invitation), a guest with an account
// lands on login, and a logged-in user sees the key field. After registering/logging in they return to this link.
Route::get('organization-invitations/{invitation}', [AcceptOrganizationInvitationController::class, 'show'])
    ->name('organization-invitations.show');

Route::post('organization-invitations/{invitation}/resend', [AcceptOrganizationInvitationController::class, 'resend'])
    ->middleware('throttle:5,10,w44')
    ->name('organization-invitations.resend');

Route::middleware(['auth'])->group(function () {
    Route::post('organization-invitations/{invitation}', [AcceptOrganizationInvitationController::class, 'store'])
        ->middleware('throttle:10,1,w45')
        ->name('organization-invitations.store');

    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

// The page of an account that has no panel (no role in the invitation chain yet): it waits for an invitation
Route::get('account/pending', AccountPendingController::class)->middleware('auth')->name('account.pending');

// The page of a deactivated account: who switched it off and when, and the chat with that person
Route::get('account/inactive', AccountInactiveController::class)->middleware('auth')->name('account.inactive');

// Chat: only people from the same organization (checked by ChatService)
Route::middleware(['auth', 'verified'])->prefix('chat')->name('chat.')->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('index');
    Route::get('contacts', [ChatController::class, 'contacts'])->name('contacts');
    Route::get('{user}/messages', [ChatController::class, 'messages'])->whereNumber('user')->name('messages');
    Route::post('{user}/typing', [ChatController::class, 'typing'])
        ->whereNumber('user')
        ->middleware('throttle:120,1,w46')
        ->name('typing');
    Route::post('{user}/messages', [ChatController::class, 'send'])
        ->whereNumber('user')
        ->middleware('throttle:60,1,w47')
        ->name('send');
});

require __DIR__.'/settings.php';

// Unknown URLs also go through the "web" group (session, user), so the 404 knows where to redirect
Route::fallback(fn () => abort(404));
