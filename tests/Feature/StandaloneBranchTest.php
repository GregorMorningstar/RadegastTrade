<?php

use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Notifications\OrganizationInvitationNotification;
use App\Support\TradeAccess;
use App\Support\UnitRef;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| A shop or a warehouse founded straight under the organization (no main headquarters), attached to a headquarters later
|--------------------------------------------------------------------------
*/

beforeEach(fn () => Notification::fake());

/** @return array<string, mixed> an organization with an owner, a shop headquarters with its manager, a warehouse headquarters, and a foreign organization */
function standaloneFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);

    $shopHq = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklepy główne']);
    $warehouseHq = WarehouseHeadquarter::create(['organization_id' => $org->id, 'name' => 'Hurtownia główna']);
    $foreignHq = ShopHeadquarter::create(['organization_id' => $other->id, 'name' => 'Obca siedziba']);

    return [
        'org' => $org, 'other' => $other, 'shopHq' => $shopHq, 'warehouseHq' => $warehouseHq, 'foreignHq' => $foreignHq,
        'owner' => User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $org->id]),
        'shopManager' => User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $org->id, 'shop_headquarter_id' => $shopHq->id]),
        'foreignOwner' => User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $other->id]),
    ];
}

/** The owner invites a leader of a standalone branch; the person accepts the invitation (still without the branch). */
function standaloneLeader(User $owner, string $type = 'shops', string $email = 'lider@example.com'): User
{
    test()->actingAs($owner)
        ->post(route('platform.organization.branches.standalone-invitations.store', $type), ['email' => $email, 'phone' => '+48'.fake()->unique()->numerify('#########')])
        ->assertRedirect()->assertSessionHasNoErrors();

    $invitation = OrganizationInvitation::latest('id')->first();
    $code = null;
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($notification) use (&$code) {
        $code = $notification->verificationCode;

        return true;
    });

    $user = User::factory()->create(['email' => $email, 'role' => UserRole::Employee]);
    test()->actingAs($user)->post(route('organization-invitations.store', $invitation), ['code' => $code])->assertRedirect();

    return $user->refresh();
}

test('właściciel zaprasza kierownika sklepu bez siedziby, a ten zakłada placówkę bezpośrednio pod organizacją', function () {
    $f = standaloneFixture();

    $leader = standaloneLeader($f['owner']);
    $invitation = OrganizationInvitation::sole();

    // the invitation names the organization and nothing else: no headquarters, no branch yet
    expect($invitation->role)->toBe(UserRole::SHOP_LEADER)->and($invitation->organization_id)->toBe($f['org']->id)
        ->and($invitation->shop_headquarter_id)->toBeNull()->and($invitation->warehouse_headquarter_id)->toBeNull()->and($invitation->shop_branch_id)->toBeNull()
        ->and($leader->role)->toBe(UserRole::SHOP_LEADER)->and($leader->organization_id)->toBe($f['org']->id)->and($leader->shop_headquarter_id)->toBeNull();

    // the leader is sent to the setup form, which knows there is no headquarters
    $this->actingAs($leader)->get(route('shop.leader.dashboard'))->assertRedirect(route('shop.leader.setup'));
    $this->get(route('shop.leader.setup'))->assertOk()->assertInertia(fn ($page) => $page->where('headquarterName', null)->where('organizationName', 'Org'));

    $this->post(route('shop.leader.setup.store'), ['name' => 'Sklep Jasło', 'organization_id' => $f['other']->id, 'shop_headquarter_id' => $f['foreignHq']->id])->assertRedirect(route('shop.leader.dashboard'));

    $branch = ShopBranch::sole();
    expect($branch->organization_id)->toBe($f['org']->id)->and($branch->shop_headquarter_id)->toBeNull()->and($branch->barcode)->not->toBeNull()
        ->and($leader->refresh()->shop_branch_id)->toBe($branch->id)->and($leader->shop_headquarter_id)->toBeNull()
        ->and($invitation->fresh()->shop_branch_id)->toBe($branch->id);

    // the panel works without a headquarters, and the setup cannot be repeated
    $this->get(route('shop.leader.dashboard'))->assertOk()->assertInertia(fn ($page) => $page->where('branch.name', 'Sklep Jasło')->where('branch.headquarter', null));
    $this->post(route('shop.leader.setup.store'), ['name' => 'Druga'])->assertForbidden();
});

test('zaproszenie placówki bez siedziby wysyła tylko właściciel organizacji, a hurtownie mają własną rolę', function () {
    $f = standaloneFixture();
    $body = ['email' => 'nowy@example.com', 'phone' => '+48600100200'];

    // the manager of a headquarters, a person of another organization and a guest cannot found branches outside any headquarters
    $this->actingAs($f['shopManager'])->post(route('platform.organization.branches.standalone-invitations.store', 'shops'), $body)->assertStatus(403);
    $this->actingAs($f['foreignOwner'])->post(route('platform.organization.branches.standalone-invitations.store', 'shops'), $body)->assertRedirect();
    expect(OrganizationInvitation::where('organization_id', $f['org']->id)->count())->toBe(0);
    auth()->logout();
    $this->post(route('platform.organization.branches.standalone-invitations.store', 'shops'), $body)->assertRedirect(route('login'));

    // a warehouse: the leader gets the warehouse role; a new invitation to the same address replaces the earlier one
    $this->actingAs($f['owner'])->post(route('platform.organization.branches.standalone-invitations.store', 'warehouses'), $body)->assertRedirect();
    $this->post(route('platform.organization.branches.standalone-invitations.store', 'warehouses'), $body)->assertRedirect();

    expect(OrganizationInvitation::where('organization_id', $f['org']->id)->where('role', UserRole::WAREHOUSE_LEADER->value)->count())->toBe(2)
        ->and(OrganizationInvitation::where('organization_id', $f['org']->id)->where('is_active', true)->count())->toBe(1);

    // unknown kinds are not routes at all
    expect($this->post('/platform/organization/kiosks/branches/standalone-invitations', $body)->status())->toBeIn([404, 405]);
});

test('nazwa placówki bez siedziby jest unikalna w organizacji, a w innej organizacji może się powtórzyć', function () {
    $f = standaloneFixture();

    $first = standaloneLeader($f['owner'], 'shops', 'pierwszy@example.com');
    $this->actingAs($first)->post(route('shop.leader.setup.store'), ['name' => 'Sklep Rynek'])->assertRedirect();

    $second = standaloneLeader($f['owner'], 'shops', 'drugi@example.com');
    $this->actingAs($second)->post(route('shop.leader.setup.store'), ['name' => 'Sklep Rynek'])->assertSessionHasErrors('name');
    expect(ShopBranch::count())->toBe(1)->and($second->refresh()->shop_branch_id)->toBeNull();

    $this->post(route('shop.leader.setup.store'), ['name' => 'Sklep Rynek 2'])->assertRedirect();
    expect(ShopBranch::count())->toBe(2);

    // the same name in another organization is a different branch
    $stranger = standaloneLeader($f['foreignOwner'], 'shops', 'obcy@example.com');
    $this->actingAs($stranger)->post(route('shop.leader.setup.store'), ['name' => 'Sklep Rynek'])->assertRedirect();
    expect(ShopBranch::count())->toBe(3);
});

test('kierownik placówki bez siedziby zaprasza pracowników i ma swój zespół', function () {
    $f = standaloneFixture();
    $leader = standaloneLeader($f['owner']);
    $this->actingAs($leader)->post(route('shop.leader.setup.store'), ['name' => 'Sklep Krosno']);

    $this->actingAs($leader)->post(route('shop.leader.workers.invitations.store'), ['email' => 'pracownik@example.com', 'phone' => '+48600100201'])->assertRedirect()->assertSessionHasNoErrors();
    $invitation = OrganizationInvitation::latest('id')->first();
    $code = null;
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($n) use (&$code, $invitation) {
        $code = $n->verificationCode;

        return $n->invitation->is($invitation);
    });

    expect($invitation->role)->toBe(UserRole::SHOP_WORKER)->and($invitation->shop_branch_id)->toBe(ShopBranch::sole()->id)->and($invitation->shop_headquarter_id)->toBeNull();

    $worker = User::factory()->create(['email' => 'pracownik@example.com', 'role' => UserRole::Employee]);
    $this->actingAs($worker)->post(route('organization-invitations.store', $invitation), ['code' => $code])->assertRedirect();

    expect($worker->refresh()->role)->toBe(UserRole::SHOP_WORKER)->and($worker->shop_branch_id)->toBe(ShopBranch::sole()->id)->and($worker->shop_headquarter_id)->toBeNull();

    $this->actingAs($worker)->get(route('shop.worker.dashboard'))->assertOk();
    $this->actingAs($worker)->get(route('shop.worker.team'))->assertOk();
    $this->actingAs($leader)->get(route('shop.leader.team'))->assertOk();
    $this->actingAs($leader)->get(route('shop.leader.workers.index'))->assertOk();
});

test('strona placówek właściciela pokazuje placówki bez siedziby i oczekujące zaproszenia', function () {
    $f = standaloneFixture();
    $leader = standaloneLeader($f['owner']);
    $this->actingAs($leader)->post(route('shop.leader.setup.store'), ['name' => 'Sklep Sanok']);

    // a pending invitation of another standalone leader
    $this->actingAs($f['owner'])->post(route('platform.organization.branches.standalone-invitations.store', 'shops'), ['email' => 'kolejny@example.com', 'phone' => '+48600100202'])->assertRedirect();

    $this->get(route('platform.organization.branches.index', 'shops'))->assertOk()->assertInertia(fn ($page) => $page
        ->has('standalone', 1)->where('standalone.0.name', 'Sklep Sanok')->where('standalone.0.people.0.email', 'lider@example.com')
        ->has('newInvitations', 1)->where('newInvitations.0.email', 'kolejny@example.com')->where('newInvitations.0.headquarter', null));

    // the other kind, and another organization, see none of it
    $this->get(route('platform.organization.branches.index', 'warehouses'))->assertInertia(fn ($page) => $page->has('standalone', 0)->has('newInvitations', 0));
    $this->actingAs($f['foreignOwner'])->get(route('platform.organization.branches.index', 'shops'))->assertInertia(fn ($page) => $page->has('standalone', 0)->has('newInvitations', 0));

    // the invitation can be withdrawn like any other
    $this->actingAs($f['owner'])->delete(route('platform.organization.invitations.destroy', OrganizationInvitation::where('email', 'kolejny@example.com')->first()->id))->assertRedirect();
    expect(OrganizationInvitation::where('email', 'kolejny@example.com')->first()->is_active)->toBeFalse();
});

test('właściciel dołącza placówkę bez siedziby do siedziby: placówka, jej osoby i zaproszenia przechodzą do siedziby', function () {
    $f = standaloneFixture();
    $leader = standaloneLeader($f['owner']);
    $this->actingAs($leader)->post(route('shop.leader.setup.store'), ['name' => 'Sklep Dukla']);
    $branch = ShopBranch::sole();

    $this->actingAs($leader)->post(route('shop.leader.workers.invitations.store'), ['email' => 'czeka@example.com', 'phone' => '+48600100203'])->assertRedirect();
    $worker = User::factory()->create(['role' => UserRole::SHOP_WORKER, 'organization_id' => $f['org']->id, 'shop_branch_id' => $branch->id]);

    // before: the manager of the headquarters has nothing to do with the branch
    $access = app(TradeAccess::class);
    expect(array_map(fn (UnitRef $unit) => $unit->ref(), $access->managedUnits($f['shopManager'])))->not->toContain('shops:branches:'.$branch->id);

    $this->actingAs($f['owner'])
        ->post(route('platform.organization.branches.attach', ['shops', $f['shopHq']->id]), ['branches' => [$branch->id]])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($branch->fresh()->shop_headquarter_id)->toBe($f['shopHq']->id)
        ->and($leader->fresh()->shop_headquarter_id)->toBe($f['shopHq']->id)->and($worker->fresh()->shop_headquarter_id)->toBe($f['shopHq']->id)
        ->and(OrganizationInvitation::where('email', 'czeka@example.com')->first()->shop_headquarter_id)->toBe($f['shopHq']->id);

    // after: the headquarters' manager manages the branch, and it is no longer "standalone"
    expect(array_map(fn (UnitRef $unit) => $unit->ref(), $access->managedUnits($f['shopManager']->fresh())))->toContain('shops:branches:'.$branch->id);
    $this->actingAs($f['owner'])->get(route('platform.organization.branches.index', 'shops'))->assertInertia(fn ($page) => $page->has('standalone', 0));
    expect(AuditLog::where('action', 'branch.attached')->count())->toBe(1);

    // the dashboard of the leader now shows the headquarters
    $this->actingAs($leader->fresh())->get(route('shop.leader.dashboard'))->assertInertia(fn ($page) => $page->where('branch.headquarter.name', 'Sklepy główne'));

    // a branch that already has a headquarters cannot be attached again (nor moved to another)
    $this->actingAs($f['owner'])->post(route('platform.organization.branches.attach', ['shops', $f['shopHq']->id]), ['branches' => [$branch->id]])->assertSessionHasErrors('branches');
});

test('dołączanie placówek do siedziby jest chronione: tylko właściciel, tylko własne placówki tego samego rodzaju', function () {
    $f = standaloneFixture();
    $shopBranch = ShopBranch::create(['organization_id' => $f['org']->id, 'shop_headquarter_id' => null, 'name' => 'Sklep wolny']);
    $warehouseBranch = WarehouseBranch::create(['organization_id' => $f['org']->id, 'warehouse_headquarter_id' => null, 'name' => 'Magazyn wolny']);
    $foreignBranch = ShopBranch::create(['organization_id' => $f['other']->id, 'shop_headquarter_id' => null, 'name' => 'Obcy sklep']);
    $attach = fn (User $by, string $type, int $headquarter, array $branches) => $this->actingAs($by)->post(route('platform.organization.branches.attach', [$type, $headquarter]), ['branches' => $branches]);

    // the manager of the headquarters cannot pull branches into it; a guest is sent to login
    $attach($f['shopManager'], 'shops', $f['shopHq']->id, [$shopBranch->id])->assertStatus(403);
    expect($shopBranch->fresh()->shop_headquarter_id)->toBeNull();
    auth()->logout();
    $this->post(route('platform.organization.branches.attach', ['shops', $f['shopHq']->id]), ['branches' => [$shopBranch->id]])->assertRedirect(route('login'));

    // another organization's branch, a foreign headquarters and an invalid list are all refused
    $attach($f['owner'], 'shops', $f['shopHq']->id, [$foreignBranch->id])->assertSessionHasErrors('branches');
    // (a branch id is meaningful only within its kind: shops are attached through the shops route, warehouses through the warehouses one)
    $attach($f['owner'], 'shops', $f['foreignHq']->id, [$shopBranch->id])->assertNotFound();
    $attach($f['owner'], 'shops', 999999, [$shopBranch->id])->assertNotFound();
    $attach($f['owner'], 'shops', $f['shopHq']->id, [])->assertSessionHasErrors('branches');
    $attach($f['owner'], 'shops', $f['shopHq']->id, ['abc'])->assertSessionHasErrors('branches.0');

    // one bad id in the list refuses the whole request: nothing is attached
    $attach($f['owner'], 'shops', $f['shopHq']->id, [$shopBranch->id, $foreignBranch->id])->assertSessionHasErrors('branches');
    expect($shopBranch->fresh()->shop_headquarter_id)->toBeNull()->and($foreignBranch->fresh()->shop_headquarter_id)->toBeNull();

    // the foreign owner cannot attach their branch to our headquarters, nor ours to theirs
    $attach($f['foreignOwner'], 'shops', $f['shopHq']->id, [$foreignBranch->id])->assertNotFound();
    $attach($f['foreignOwner'], 'shops', $f['foreignHq']->id, [$shopBranch->id])->assertSessionHasErrors('branches');

    // the right ones work, the warehouse into the warehouse headquarters
    $attach($f['owner'], 'warehouses', $f['warehouseHq']->id, [$warehouseBranch->id])->assertSessionHasNoErrors();
    $attach($f['owner'], 'shops', $f['shopHq']->id, [$shopBranch->id])->assertSessionHasNoErrors();
    expect($warehouseBranch->fresh()->warehouse_headquarter_id)->toBe($f['warehouseHq']->id)->and($shopBranch->fresh()->shop_headquarter_id)->toBe($f['shopHq']->id);
});

test('dołączanie nie łączy placówek o tej samej nazwie w jednej siedzibie', function () {
    $f = standaloneFixture();
    ShopBranch::create(['organization_id' => $f['org']->id, 'shop_headquarter_id' => $f['shopHq']->id, 'name' => 'Sklep Rynek']);
    $same = ShopBranch::create(['organization_id' => $f['org']->id, 'shop_headquarter_id' => null, 'name' => 'sklep rynek']);
    $a = ShopBranch::create(['organization_id' => $f['org']->id, 'shop_headquarter_id' => null, 'name' => 'Sklep Duplikat']);
    $b = ShopBranch::create(['organization_id' => $f['org']->id, 'shop_headquarter_id' => null, 'name' => 'Sklep Duplikat']);

    $this->actingAs($f['owner'])->post(route('platform.organization.branches.attach', ['shops', $f['shopHq']->id]), ['branches' => [$same->id]])->assertSessionHasErrors('branches');
    expect($same->fresh()->shop_headquarter_id)->toBeNull();

    // two with one name cannot be attached together either (the second is the same name as the first, once attached)
    $this->post(route('platform.organization.branches.attach', ['shops', $f['shopHq']->id]), ['branches' => [$a->id, $b->id]])->assertSessionHasErrors('branches');
    expect($a->fresh()->shop_headquarter_id)->toBeNull()->and($b->fresh()->shop_headquarter_id)->toBeNull();

    $same->update(['name' => 'Sklep Nowy']);
    $this->post(route('platform.organization.branches.attach', ['shops', $f['shopHq']->id]), ['branches' => [$same->id]])->assertSessionHasNoErrors();
    expect($same->fresh()->shop_headquarter_id)->toBe($f['shopHq']->id);
});

test('placówka bez siedziby jest w strukturze: menu jednostek, lista administratora, prawa w module handlowym', function () {
    $f = standaloneFixture();
    $leader = standaloneLeader($f['owner']);
    $this->actingAs($leader)->post(route('shop.leader.setup.store'), ['name' => 'Sklep Przemyśl']);
    $branch = ShopBranch::sole();

    // the side menu ("Jednostka") lists it for the owner and for its leader
    $this->actingAs($f['owner'])->get(route('platform.organization.dashboard'))->assertInertia(fn ($page) => $page->where('units', fn ($units) => collect($units)->contains(fn ($unit) => $unit['name'] === 'Sklep Przemyśl' && $unit['level'] === 'branch')));
    $this->actingAs($leader)->get(route('shop.leader.dashboard'))->assertInertia(fn ($page) => $page->where('units', fn ($units) => collect($units)->contains(fn ($unit) => $unit['name'] === 'Sklep Przemyśl')));

    // the administrator's list of branches shows it as one without a headquarters
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin)->get(route('platform.admin.shops.branches.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('rows', fn ($rows) => collect($rows)->contains(fn ($row) => $row['name'] === 'Sklep Przemyśl' && $row['headquarter'] === null)));

    // the trade module treats it as a unit of its leader and of the owner, nothing more
    $access = app(TradeAccess::class);
    $ref = 'shops:branches:'.$branch->id;
    expect($access->ownUnit($leader)?->ref())->toBe($ref)->and(array_map(fn (UnitRef $unit) => $unit->ref(), $access->managedUnits($leader)))->toBe([$ref])
        ->and(array_map(fn (UnitRef $unit) => $unit->ref(), $access->managedUnits($f['owner'])))->toContain($ref)
        ->and(array_map(fn (UnitRef $unit) => $unit->ref(), $access->managedUnits($f['shopManager'])))->not->toContain($ref)
        ->and(array_map(fn (UnitRef $unit) => $unit->ref(), $access->managedUnits($f['foreignOwner'])))->not->toContain($ref);
});

test('schemat: siedziba placówki jest opcjonalna, a placówka w siedzibie nadal musi być z tej samej organizacji', function () {
    $f = standaloneFixture();

    foreach (['shop_branches' => 'shop_headquarter_id', 'warehouse_branches' => 'warehouse_headquarter_id'] as $table => $column) {
        $columns = collect(Illuminate\Support\Facades\Schema::getColumns($table))->keyBy('name');
        expect($columns[$column]['nullable'])->toBeTrue();
    }

    ShopBranch::create(['organization_id' => $f['org']->id, 'shop_headquarter_id' => null, 'name' => 'Bez siedziby']);
    WarehouseBranch::create(['organization_id' => $f['org']->id, 'warehouse_headquarter_id' => null, 'name' => 'Magazyn bez siedziby']);
    expect(ShopBranch::whereNull('shop_headquarter_id')->count())->toBe(1)->and(WarehouseBranch::whereNull('warehouse_headquarter_id')->count())->toBe(1);

    // a branch that has a headquarters is not affected: it still has to be in the same organization as that headquarters
    $branch = ShopBranch::create(['organization_id' => $f['org']->id, 'shop_headquarter_id' => $f['shopHq']->id, 'name' => 'W siedzibie']);
    expect($branch->headquarter->name)->toBe('Sklepy główne');
    expect(fn () => ShopBranch::create(['organization_id' => $f['org']->id, 'shop_headquarter_id' => $f['foreignHq']->id, 'name' => 'Cudza siedziba']))->toThrow(Illuminate\Database\QueryException::class);
});
