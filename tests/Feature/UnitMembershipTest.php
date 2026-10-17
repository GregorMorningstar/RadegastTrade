<?php

use App\Enums\HeadquarterType;
use App\Enums\UnitLevel;
use App\Enums\UnitPosition;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\UnitMembership;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\UnitMembershipRepositoryInterface;

/**
 * Organization with a shop headquarters (manager) with a branch (leader + worker), a second shop headquarters,
 * a warehouse headquarters with a branch, a free shop worker and a foreign organization.
 *
 * @return array<string, mixed>
 */
/** "shops/branches/worker": the kind of the unit and the position, read from the columns of a membership. */
function unitKind(UnitMembership $membership): string
{
    $type = ($membership->shop_headquarter_id || $membership->shop_branch_id) ? 'shops' : 'warehouses';
    $level = ($membership->shop_branch_id || $membership->warehouse_branch_id) ? 'branches' : 'headquarters';

    return "$type/$level/{$membership->position->value}";
}

function unitFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);

    $owner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $org->id]);

    $hq = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Siedziba sklepu']);
    $hq2 = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Druga siedziba']);
    $branch = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hq->id, 'name' => 'Sklep 1']);
    $branch2 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hq2->id, 'name' => 'Sklep 2']);

    $manager = User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $org->id, 'shop_headquarter_id' => $hq->id, 'name' => 'Kierownik siedziby']);
    $manager2 = User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $org->id, 'shop_headquarter_id' => $hq2->id, 'name' => 'Kierownik drugiej']);
    $leader = User::factory()->create(['role' => UserRole::SHOP_LEADER, 'organization_id' => $org->id, 'shop_headquarter_id' => $hq->id, 'shop_branch_id' => $branch->id, 'name' => 'Lider']);
    $worker = User::factory()->create(['role' => UserRole::SHOP_WORKER, 'organization_id' => $org->id, 'shop_headquarter_id' => $hq->id, 'shop_branch_id' => $branch->id, 'name' => 'Pracownik']);
    $free = User::factory()->create(['role' => UserRole::SHOP_WORKER, 'organization_id' => $org->id, 'name' => 'Wolny']);

    $warehouse = WarehouseHeadquarter::create(['organization_id' => $org->id, 'name' => 'Hurtownia']);
    $warehouseBranch = WarehouseBranch::create(['organization_id' => $org->id, 'warehouse_headquarter_id' => $warehouse->id, 'name' => 'Magazyn 1']);
    $warehouseWorker = User::factory()->create(['role' => UserRole::WAREHOUSE_WORKER, 'organization_id' => $org->id, 'warehouse_headquarter_id' => $warehouse->id, 'warehouse_branch_id' => $warehouseBranch->id, 'name' => 'Magazynier']);

    $foreignHq = ShopHeadquarter::create(['organization_id' => $other->id, 'name' => 'Obca']);
    $foreign = User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $other->id, 'shop_headquarter_id' => $foreignHq->id, 'name' => 'Obcy']);

    return compact('org', 'other', 'owner', 'hq', 'hq2', 'branch', 'branch2', 'manager', 'manager2', 'leader', 'worker', 'free', 'warehouse', 'warehouseBranch', 'warehouseWorker', 'foreignHq', 'foreign');
}

function membersUrl(string $type, string $level, int $unit, ?int $user = null): string
{
    return route($user === null ? 'memberships.index' : 'memberships.update', array_filter(['type' => $type, 'level' => $level, 'unit' => $unit, 'user' => $user]));
}

test('tabela członkostw odzwierciedla kierowników i pracowników siedzib oraz placówek', function () {
    $f = unitFixture();

    $row = fn (User $user) => UnitMembership::where('user_id', $user->id)->first();

    expect($row($f['manager']))
        ->position->toBe(UnitPosition::Manager)
        ->shop_headquarter_id->toBe($f['hq']->id)
        ->shop_branch_id->toBeNull()
        ->and($row($f['leader']))
        ->position->toBe(UnitPosition::Manager)
        ->shop_branch_id->toBe($f['branch']->id)
        ->shop_headquarter_id->toBeNull()
        ->and($row($f['worker']))
        ->position->toBe(UnitPosition::Worker)
        ->shop_branch_id->toBe($f['branch']->id)
        ->and($row($f['warehouseWorker']))
        ->warehouse_branch_id->toBe($f['warehouseBranch']->id)
        // a person without a unit and an organization owner have no row
        ->and($row($f['free']))->toBeNull()
        ->and($row($f['owner']))->toBeNull()
        ->and(UnitMembership::count())->toBe(6);

    expect(UnitMembership::where('shop_branch_id', $f['branch']->id)->count())->toBe(2)
        ->and(UnitMembership::where('shop_headquarter_id', $f['hq']->id)->count())->toBe(1);
});

test('zmiana roli lub jednostki konta aktualizuje członkostwo, a odłączenie je usuwa', function () {
    $f = unitFixture();

    // a leader demoted to a worker of the same branch
    $f['leader']->forceFill(['role' => UserRole::SHOP_WORKER])->save();
    expect(UnitMembership::where('user_id', $f['leader']->id)->first()->position)->toBe(UnitPosition::Worker);

    // moved to another branch
    $f['leader']->forceFill(['shop_branch_id' => $f['branch2']->id, 'shop_headquarter_id' => $f['hq2']->id])->save();
    expect(UnitMembership::where('user_id', $f['leader']->id)->first()->shop_branch_id)->toBe($f['branch2']->id);

    // a person works in one unit only: still one row for this user
    expect(UnitMembership::where('user_id', $f['leader']->id)->count())->toBe(1);

    // detached from the unit
    $f['leader']->forceFill(['shop_branch_id' => null, 'shop_headquarter_id' => null])->save();
    expect(UnitMembership::where('user_id', $f['leader']->id)->exists())->toBeFalse();

    // an account of a leader that only waits to found the branch (headquarters, no branch) is not a member yet
    $pending = User::factory()->create(['role' => UserRole::SHOP_LEADER, 'organization_id' => $f['org']->id, 'shop_headquarter_id' => $f['hq']->id]);
    expect(UnitMembership::where('user_id', $pending->id)->exists())->toBeFalse();
});

test('repozytorium: place utrzymuje konto i członkostwo razem', function () {
    $f = unitFixture();
    $repository = app(UnitMembershipRepositoryInterface::class);

    $membership = $repository->place($f['free'], HeadquarterType::Shop, UnitLevel::Branch, $f['branch2']->id, UnitPosition::Manager);
    expect($membership->position)->toBe(UnitPosition::Manager)
        ->and($f['free']->fresh()->role)->toBe(UserRole::SHOP_LEADER)
        ->and($f['free']->fresh()->shop_headquarter_id)->toBe($f['hq2']->id);

    // the same person moves to a headquarters as a manager: still one row
    $repository->place($f['free']->fresh(), HeadquarterType::Shop, UnitLevel::Headquarters, $f['hq']->id, UnitPosition::Manager);
    expect($f['free']->fresh()->role)->toBe(UserRole::SHOP_OWNER)
        ->and(UnitMembership::where('user_id', $f['free']->id)->count())->toBe(1)
        ->and($f['free']->fresh()->shop_branch_id)->toBeNull();

    expect(fn () => $repository->place($f['free']->fresh(), HeadquarterType::Shop, UnitLevel::Headquarters, $f['hq']->id, UnitPosition::Worker))
        ->toThrow(InvalidArgumentException::class);
});

test('DakarSeeder tworzy po kierowniku siedziby, kierowniku placówki i trzech pracowników w hurtowni i sklepie', function () {
    $this->seed(Database\Seeders\DakarSeeder::class);

    $emails = User::where('email', 'like', 'dakar_%@test.pl')->pluck('email')->sort()->values()->all();

    expect($emails)->toBe([
        'dakar_admin@test.pl',
        'dakar_employee@test.pl',
        'dakar_manager@test.pl',
        'dakar_organization@test.pl',
        'dakar_shop_leader@test.pl',
        'dakar_shop_owner@test.pl',
        'dakar_shop_worker_1@test.pl',
        'dakar_shop_worker_2@test.pl',
        'dakar_shop_worker_3@test.pl',
        'dakar_warehouse_leader@test.pl',
        'dakar_warehouse_owner@test.pl',
        'dakar_warehouse_worker_1@test.pl',
        'dakar_warehouse_worker_2@test.pl',
        'dakar_warehouse_worker_3@test.pl',
    ]);

    $users = User::where('email', 'like', 'dakar_%@test.pl')->get();
    expect($users->every(fn (User $u) => Hash::check('qwer1234', $u->password) && $u->hasVerifiedEmail() && $u->phone !== null))->toBeTrue()
        ->and($users->pluck('phone')->unique())->toHaveCount(14);

    // in the table: per group a headquarters manager, a branch manager and three branch workers
    $byPosition = UnitMembership::query()->get()->groupBy(fn ($m) => unitKind($m))->map->count();
    expect($byPosition->all())->toBe([
        'warehouses/headquarters/manager' => 1,
        'warehouses/branches/manager' => 1,
        'warehouses/branches/worker' => 3,
        'shops/headquarters/manager' => 1,
        'shops/branches/manager' => 1,
        'shops/branches/worker' => 3,
    ]);

    // it can be run again: nothing is duplicated
    $this->seed(Database\Seeders\DakarSeeder::class);
    expect(User::where('email', 'like', 'dakar_%@test.pl')->count())->toBe(14)->and(Organization::where('NIP', '6851047393')->count())->toBe(1);
});

test('DakarSeeder doprowadza ręcznie założone konta DAKAR do stanowisk z ich nazw, a obce konta zostawia', function () {
    $org = Organization::create(['name' => 'DAKAR', 'NIP' => '6851047393']);
    $hq = WarehouseHeadquarter::create(['organization_id' => $org->id, 'name' => 'Hurtownia DAKAR']);
    $branch = WarehouseBranch::create(['organization_id' => $org->id, 'warehouse_headquarter_id' => $hq->id, 'name' => 'Magazyn DAKAR']);

    $founder = User::factory()->create(['email' => 'dakar1@o2.pl', 'role' => UserRole::WAREHOUSE_WORKER, 'organization_id' => $org->id]);
    $named = User::factory()->create(['email' => 'dakar_ph1@test.pl', 'role' => UserRole::WAREHOUSE_OWNER, 'organization_id' => $org->id]);
    $leader = User::factory()->create(['email' => 'dakar_h1@test.pl', 'role' => UserRole::WAREHOUSE_LEADER, 'organization_id' => $org->id, 'warehouse_headquarter_id' => $hq->id, 'warehouse_branch_id' => $branch->id]);

    $this->seed(Database\Seeders\DakarSeeder::class);

    expect($founder->fresh())->role->toBe(UserRole::WAREHOUSE_OWNER)->warehouse_headquarter_id->toBe($hq->id)->warehouse_branch_id->toBeNull()
        ->and($named->fresh())->role->toBe(UserRole::WAREHOUSE_WORKER)->warehouse_branch_id->toBe($branch->id)->warehouse_headquarter_id->toBe($hq->id)
        ->and($leader->fresh())->role->toBe(UserRole::WAREHOUSE_LEADER)->warehouse_branch_id->toBe($branch->id)
        // the existing warehouse structure is used, not duplicated
        ->and(WarehouseHeadquarter::where('organization_id', $org->id)->count())->toBe(1)
        ->and(WarehouseBranch::where('organization_id', $org->id)->count())->toBe(1)
        // the seeder's own accounts joined the existing headquarters and branch
        ->and(User::where('email', 'dakar_warehouse_owner@test.pl')->first()->warehouse_headquarter_id)->toBe($hq->id);
});

test('właściciel organizacji włącza i wyłącza konta osób ze swojej organizacji', function () {
    $f = unitFixture();
    $url = fn (User $user) => route('memberships.active', $user->id);

    $this->actingAs($f['owner'])->patchJson($url($f['worker']), ['active' => false])
        ->assertOk()->assertJsonPath('is_active', false);
    expect($f['worker']->fresh()->is_active)->toBeFalse();

    $this->patchJson($url($f['worker']), ['active' => true])->assertOk();
    expect($f['worker']->fresh()->is_active)->toBeTrue();

    // the flag is visible in the structure preview next to the role value
    $f['leader']->forceFill(['is_active' => false])->save();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->getJson(route('platform.headquarters.tree', 'shops'))
        ->assertJsonFragment(['name' => 'Lider', 'role_value' => UserRole::SHOP_LEADER->value, 'is_active' => false])
        ->assertJsonFragment(['name' => 'Pracownik', 'is_active' => true]);

    // validation
    $this->actingAs($f['owner'])->patchJson($url($f['worker']), [])->assertUnprocessable()->assertJsonValidationErrors('active');
});

test('konta nie włączy ani nie wyłączy nikt spoza właściciela organizacji i lidera placówki', function () {
    $f = unitFixture();
    $url = fn (User $user) => route('memberships.active', $user->id);

    // the owner cannot switch himself, another owner, a platform account or someone from another organization
    $foreignOwner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $f['other']->id]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    foreach ([$f['owner'], $foreignOwner, $admin, $f['foreign']] as $target) {
        $this->actingAs($f['owner'])->patchJson($url($target), ['active' => false])->assertUnprocessable();
        expect($target->fresh()->is_active)->toBeTrue();
    }

    // the manager of a headquarters and a worker have no such right, nor has the platform staff
    foreach (['manager', 'worker'] as $who) {
        $this->actingAs($f[$who])->patchJson($url($f['free']), ['active' => false])->assertForbidden();
    }
    $this->actingAs($admin)->patchJson($url($f['worker']), ['active' => false])->assertForbidden();

    // a leader: not himself, not a colleague leader or someone outside his branch
    $this->actingAs($f['leader'])->patchJson($url($f['leader']), ['active' => false])->assertUnprocessable();
    $this->patchJson($url($f['free']), ['active' => false])->assertUnprocessable();
    $this->patchJson($url($f['manager']), ['active' => false])->assertUnprocessable();
    $this->patchJson($url($f['warehouseWorker']), ['active' => false])->assertUnprocessable();

    // the owner of another organization
    $this->actingAs($foreignOwner)->patchJson($url($f['worker']), ['active' => false])->assertUnprocessable();

    expect($f['worker']->fresh()->is_active)->toBeTrue()->and($f['leader']->fresh()->is_active)->toBeTrue();
});

test('lider placówki dezaktywuje pracowników swojej placówki, a system zapisuje kto i kiedy', function () {
    $f = unitFixture();
    $this->travelTo(now()->setTime(12, 30));

    $this->actingAs($f['leader'])->patchJson(route('memberships.active', $f['worker']->id), ['active' => false])
        ->assertOk()->assertJsonPath('is_active', false);

    $worker = $f['worker']->fresh();
    expect($worker->is_active)->toBeFalse()
        ->and($worker->deactivated_by)->toBe($f['leader']->id)
        ->and($worker->deactivated_at->format('H:i'))->toBe('12:30')
        ->and($worker->deactivatedBy->name)->toBe('Lider');

    // switching on again clears who and when
    $this->patchJson(route('memberships.active', $f['worker']->id), ['active' => true])->assertOk();
    $worker = $f['worker']->fresh();
    expect($worker->is_active)->toBeTrue()->and($worker->deactivated_by)->toBeNull()->and($worker->deactivated_at)->toBeNull();
});

test('zespół pokazuje właścicielowi i liderowi, czyje konto mogą przełączyć, oraz stan konta', function () {
    $f = unitFixture();
    $this->actingAs($f['leader'])->patchJson(route('memberships.active', $f['worker']->id), ['active' => false])->assertOk();

    $rows = fn ($response) => collect($response->viewData('page')['props']['members'])->keyBy('name');

    // the organization owner: everyone from the structure can be switched, himself not
    $owner = $rows($this->actingAs($f['owner'])->get(route('platform.organization.team')));
    expect($owner['Pracownik']['canToggle'])->toBeTrue()
        ->and($owner['Pracownik']['status'])->toBe('inactive')
        ->and($owner['Pracownik']['detail'])->toContain('wyłączone przez Lider')
        ->and($owner['Kierownik siedziby']['canToggle'])->toBeTrue()
        ->and($owner['Pracownik']['userId'])->toBe($f['worker']->id);

    // the leader of the branch: only the workers of the branch
    $leader = $rows($this->actingAs($f['leader'])->get(route('shop.leader.team')));
    expect($leader['Pracownik']['canToggle'])->toBeTrue()->and($leader['Lider']['canToggle'])->toBeFalse();

    // the worker switches nobody
    $this->actingAs($f['free'])->get(route('shop.worker.team'))->assertForbidden();
    $this->actingAs($f['worker']->fresh()->forceFill(['is_active' => true]))->get(route('shop.worker.team'))->assertOk();
});

test('dezaktywowane konto loguje się, ale widzi tylko stronę o wyłączonym koncie', function () {
    $f = unitFixture();
    $this->actingAs($f['owner'])->patchJson(route('memberships.active', $f['worker']->id), ['active' => false])->assertOk();

    $worker = $f['worker']->fresh();

    // it can log in with the right password...
    $this->app['auth']->forgetGuards();
    $this->post(route('login.store'), ['email' => $worker->email, 'password' => 'password'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($worker);

    // ...but every panel page leads to the "account is not active" page, JSON requests get 403
    $this->get(route('shop.worker.dashboard'))->assertRedirect(route('account.inactive'));
    $this->get(route('chat.index'))->assertRedirect(route('account.inactive'));
    $this->getJson(route('shop.worker.team'))->assertForbidden();

    $this->get(route('account.inactive'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('account/inactive')
            ->where('deactivatedBy.name', $f['owner']->name)
            ->where('deactivatedBy.role', UserRole::ORGANIZATION_OWNER->label())
            ->has('deactivatedAt'));

    // it can log out
    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();

    // switched on again, the person is back in the panel and the inactive page sends them away
    $worker->forceFill(['is_active' => true])->save();
    $this->actingAs($worker)->get(route('shop.worker.dashboard'))->assertOk();
    $this->get(route('account.inactive'))->assertRedirect(route($worker->role->dashboardRoute()));
});

test('dezaktywowany pisze na czacie tylko do osoby, która go wyłączyła, i do administratorów', function () {
    $f = unitFixture();
    $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Administrator systemu']);

    $this->actingAs($f['leader'])->patchJson(route('memberships.active', $f['worker']->id), ['active' => false])->assertOk();
    $worker = $f['worker']->fresh();

    $names = collect($this->actingAs($worker)->getJson(route('chat.contacts'))->assertOk()->json('contacts'))->pluck('name')->sort()->values();
    expect($names->all())->toContain('Lider', 'Administrator systemu')
        ->not->toContain('Kierownik siedziby', 'Magazynier')
        ->not->toContain('Pracownik');

    // to the person who deactivated and to an administrator: yes
    $this->postJson(route('chat.send', $f['leader']), ['body' => 'Dlaczego?'])->assertCreated();
    $this->postJson(route('chat.send', $admin), ['body' => 'Proszę o pomoc'])->assertCreated();
    // to anyone else: no
    $this->postJson(route('chat.send', $f['manager']), ['body' => 'Hej'])->assertForbidden();
    $this->getJson(route('chat.messages', $f['manager']))->assertForbidden();

    // the leader sees the message and may answer
    $this->actingAs($f['leader'])->getJson(route('chat.messages', $worker))->assertJsonPath('messages.0.body', 'Dlaczego?');
    $this->postJson(route('chat.send', $worker), ['body' => 'Rozmowa w poniedziałek'])->assertCreated();
});

test('role: lista wartości', function () {
    // exactly the roles of the platform
    expect(UserRole::getValues())->toBe(['admin', 'manager', 'employee', 'organization_owner', 'warehouse_owner', 'warehouse_leader', 'warehouse_worker', 'shop_owner', 'shop_leader', 'shop_worker']);
});

test('panele mają tylko role z łańcucha zaproszeń, manager i pracownik platformy trafiają na stronę oczekiwania', function () {
    // the panels of the manager and the employee are gone
    $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));
    $this->get('/platform/manager')->assertNotFound();
    $this->get('/platform/employee/dashboard')->assertNotFound();

    foreach ([UserRole::Admin, UserRole::ORGANIZATION_OWNER, UserRole::SHOP_OWNER, UserRole::SHOP_LEADER, UserRole::SHOP_WORKER, UserRole::WAREHOUSE_OWNER, UserRole::WAREHOUSE_LEADER, UserRole::WAREHOUSE_WORKER] as $role) {
        expect($role->hasPanel())->toBeTrue()
            ->and(Route::has($role->dashboardRoute()))->toBeTrue();
    }

    foreach ([UserRole::Manager, UserRole::Employee] as $role) {
        $user = User::factory()->create(['role' => $role]);

        expect($role->hasPanel())->toBeFalse()->and($role->dashboardRoute())->toBe('account.pending');

        $this->actingAs($user)->get(route('dashboard', $user->currentTeam->slug))->assertRedirect(route('account.pending'));
        $this->get(route('account.pending'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('account/pending')->where('email', $user->email));
    }

    // an account with a panel has nothing to do on that page
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get(route('account.pending'))->assertRedirect(route('platform.admin.dashboard'));
});

test('DakarSeeder tworzy konta platformy i właściciela organizacji DAKAR', function () {
    $this->seed(Database\Seeders\DakarSeeder::class);

    $account = fn (string $email) => User::where('email', $email)->firstOrFail();

    // the platform accounts have no organization and the roles outside the units
    foreach (['dakar_admin@test.pl' => UserRole::Admin, 'dakar_manager@test.pl' => UserRole::Manager, 'dakar_employee@test.pl' => UserRole::Employee] as $email => $role) {
        expect($account($email))->role->toBe($role)->organization_id->toBeNull()
            ->and(Hash::check('qwer1234', $account($email)->password))->toBeTrue()
            ->and($account($email)->hasVerifiedEmail())->toBeTrue()
            ->and($account($email)->barcode)->not->toBeNull();
    }

    // the owner belongs to the DAKAR organization and is in no unit
    $owner = $account('dakar_organization@test.pl');
    expect($owner->role)->toBe(UserRole::ORGANIZATION_OWNER)
        ->and($owner->organization->NIP)->toBe('6851047393')
        ->and(UnitMembership::where('user_id', $owner->id)->exists())->toBeFalse();

    // the owner sees the DAKAR structure the seeder built
    $this->actingAs($owner)->getJson(route('platform.organization.headquarters.tree', 'shops'))
        ->assertOk()->assertJsonPath('total', 1);

    // and the administrator, the manager and the employee are not counted as people of the units
    expect(UnitMembership::count())->toBe(10);
});

test('osoby nie da się przypisać do jednostki innej organizacji', function () {
    $f = unitFixture();
    $foreignOrganization = Organization::create(['name' => 'Obca']);
    $foreignHq = ShopHeadquarter::create(['organization_id' => $foreignOrganization->id, 'name' => 'Obca siedziba']);
    $repository = app(UnitMembershipRepositoryInterface::class);

    expect(fn () => $repository->place($f['free'], HeadquarterType::Shop, UnitLevel::Headquarters, $foreignHq->id, UnitPosition::Manager))
        ->toThrow(InvalidArgumentException::class)
        ->and($repository->findUnit($f['free']->organization_id, HeadquarterType::Shop, UnitLevel::Headquarters, $foreignHq->id))->toBeNull();
});

test('listy struktury wszystkich organizacji zwraca serwis tylko administratorowi, także bez middleware', function () {
    $f = unitFixture();
    $service = app(App\Services\Contracts\StructureListingServiceInterface::class);

    foreach (['free'] as $who) {
        expect(fn () => $service->headquarters($f[$who], HeadquarterType::Shop))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class)
            ->and(fn () => $service->branches($f[$who], HeadquarterType::Shop))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    }

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    expect($service->headquarters($admin, HeadquarterType::Shop))->toBeArray();
});
