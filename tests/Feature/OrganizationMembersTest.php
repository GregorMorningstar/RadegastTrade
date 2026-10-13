<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;

/**
 * Organization with an owner, a shop headquarters (manager + branch with leader and worker),
 * a warehouse headquarters (manager) and a foreign organization with its own shop headquarters.
 *
 * @return array{org: Organization, other: Organization, hq: ShopHeadquarter, branch: ShopBranch}
 */
function membersFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);

    User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $org->id, 'name' => 'Właściciel']);

    $hq = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Siedziba sklepu']);
    $branch = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hq->id, 'name' => 'Placówka 1']);
    User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $org->id, 'shop_headquarter_id' => $hq->id, 'name' => 'Kierownik sklepu']);
    User::factory()->create(['role' => UserRole::SHOP_LEADER, 'organization_id' => $org->id, 'shop_headquarter_id' => $hq->id, 'shop_branch_id' => $branch->id, 'name' => 'Lider']);
    User::factory()->create(['role' => UserRole::SHOP_WORKER, 'organization_id' => $org->id, 'shop_headquarter_id' => $hq->id, 'shop_branch_id' => $branch->id, 'name' => 'Pracownik']);

    $warehouse = WarehouseHeadquarter::create(['organization_id' => $org->id, 'name' => 'Hurtownia główna']);
    User::factory()->create(['role' => UserRole::WAREHOUSE_OWNER, 'organization_id' => $org->id, 'warehouse_headquarter_id' => $warehouse->id, 'name' => 'Magazynier']);

    $foreign = ShopHeadquarter::create(['organization_id' => $other->id, 'name' => 'Obca siedziba']);
    User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $other->id, 'shop_headquarter_id' => $foreign->id, 'name' => 'Obcy kierownik']);

    return ['org' => $org, 'other' => $other, 'hq' => $hq, 'branch' => $branch];
}

test('administrator i manager widzą podział organizacji na siedziby, placówki i osoby', function (UserRole $role) {
    $f = membersFixture();

    $response = $this->actingAs(User::factory()->create(['role' => $role]))
        ->getJson(route('platform.organizations.members', $f['org']))
        ->assertOk()
        ->assertJsonPath('group', 'shop')
        ->assertJsonPath('owners.0.name', 'Właściciel')
        ->assertJsonPath('headquarters.total', 1)
        ->assertJsonPath('headquarters.data.0.name', 'Siedziba sklepu')
        ->assertJsonPath('headquarters.data.0.people.0.name', 'Kierownik sklepu')
        ->assertJsonPath('headquarters.data.0.branches.0.name', 'Placówka 1')
        ->assertJsonCount(2, 'headquarters.data.0.branches.0.people')
        ->assertJsonMissing(['name' => 'Obcy kierownik'])
        ->assertJsonMissing(['name' => 'Magazynier']);

    expect(array_keys($response->json('headquarters.data.0.branches.0.people.0')))->toContain('phone', 'barcode', 'role', 'id');
    // branch people do not repeat among the headquarters' own people
    expect(collect($response->json('headquarters.data.0.people'))->pluck('name')->all())->toBe(['Kierownik sklepu']);
})->with([UserRole::Admin, UserRole::Manager, UserRole::Employee]);

test('hurtownie są osobną zakładką podziału', function () {
    $f = membersFixture();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->getJson(route('platform.organizations.members', ['organization' => $f['org'], 'type' => 'warehouses']))
        ->assertJsonPath('group', 'warehouse')
        ->assertJsonPath('headquarters.data.0.name', 'Hurtownia główna')
        ->assertJsonPath('headquarters.data.0.people.0.name', 'Magazynier')
        ->assertJsonMissing(['name' => 'Siedziba sklepu']);
});

test('inne role nie mogą podglądać osób organizacji', function (UserRole $role) {
    $f = membersFixture();
    $user = User::factory()->create(['role' => $role, 'organization_id' => $f['org']->id]);

    $this->actingAs($user)->getJson(route('platform.organizations.members', $f['org']))->assertForbidden();
})->with([UserRole::ORGANIZATION_OWNER, UserRole::SHOP_OWNER, UserRole::WAREHOUSE_OWNER, UserRole::SHOP_LEADER, UserRole::SHOP_WORKER]);

test('gość nie podgląda osób organizacji', function () {
    $f = membersFixture();

    $this->getJson(route('platform.organizations.members', $f['org']))->assertUnauthorized();
});

test('siedziby są stronicowane po 5, a parametry walidowane', function () {
    $f = membersFixture();
    foreach (range(1, 5) as $i) {
        ShopHeadquarter::create(['organization_id' => $f['org']->id, 'name' => "Dodatkowa $i"]);
    }
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $this->getJson(route('platform.organizations.members', $f['org']))
        ->assertJsonPath('headquarters.total', 6)
        ->assertJsonPath('headquarters.last_page', 2)
        ->assertJsonCount(5, 'headquarters.data');
    $this->getJson(route('platform.organizations.members', ['organization' => $f['org'], 'page' => 2]))
        ->assertJsonPath('headquarters.current_page', 2)
        ->assertJsonCount(1, 'headquarters.data');

    // the tree of all headquarters is paged by 5 too
    $this->getJson(route('platform.headquarters.tree', 'shops'))
        ->assertJsonPath('total', 7)
        ->assertJsonCount(5, 'data');

    $this->getJson(route('platform.organizations.members', ['organization' => $f['org'], 'type' => 'x']))->assertUnprocessable();
    $this->getJson(route('platform.organizations.members', ['organization' => $f['org'], 'q' => str_repeat('a', 101)]))->assertUnprocessable();
});

test('wyszukiwanie znajduje siedzibę po jej danych, organizacji, placówce i osobie', function (string $term, array $expected) {
    $f = membersFixture();
    $f['hq']->update(['city' => 'Jasło']);
    ShopHeadquarter::create(['organization_id' => $f['other']->id, 'name' => 'Trzecia siedziba']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Employee]));

    $names = collect($this->getJson(route('platform.headquarters.tree', ['type' => 'shops', 'q' => $term]))->json('data'))->pluck('name')->sort()->values()->all();

    expect($names)->toBe($expected);
})->with([
    'nazwa siedziby' => ['Siedziba sklepu', ['Siedziba sklepu']],
    'nazwa organizacji' => ['Inna', ['Obca siedziba', 'Trzecia siedziba']],
    'miasto' => ['Jasło', ['Siedziba sklepu']],
    'nazwa placówki' => ['Placówka 1', ['Siedziba sklepu']],
    'osoba z placówki' => ['Lider', ['Siedziba sklepu']],
    'osoba z siedziby' => ['Obcy kierownik', ['Obca siedziba']],
    'brak wyników' => ['nie ma takiego', []],
    'znaki specjalne są traktowane dosłownie' => ['%', []],
]);

test('wyszukiwanie w organizacji zawęża siedziby tylko tej organizacji', function () {
    $f = membersFixture();
    $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));

    $this->getJson(route('platform.organizations.members', ['organization' => $f['org'], 'q' => 'Obcy']))
        ->assertJsonPath('headquarters.total', 0);
    $this->getJson(route('platform.organizations.members', ['organization' => $f['org'], 'q' => 'Lider']))
        ->assertJsonPath('headquarters.total', 1)
        ->assertJsonPath('owners.0.name', 'Właściciel');
});

test('wyszukiwanie jest zamknięte dla ról spoza podglądu', function () {
    $f = membersFixture();
    $user = User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $f['org']->id]);

    $this->actingAs($user)->getJson(route('platform.headquarters.tree', ['type' => 'shops', 'q' => 'Lider']))->assertForbidden();
    $this->getJson(route('platform.organizations.members', ['organization' => $f['org'], 'q' => 'Lider']))->assertForbidden();
});

test('siedziba i placówka mają dane kontaktowe w drzewie', function () {
    $f = membersFixture();
    $f['hq']->update(['NIP' => '6852339117', 'email' => 'siedziba@example.pl', 'phone' => '+48600100200', 'street' => 'Długa', 'building_number' => '5', 'apartment_number' => '2', 'postal_code' => '38-200', 'city' => 'Jasło']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Manager]))
        ->getJson(route('platform.organizations.members', $f['org']))
        ->assertJsonPath('headquarters.data.0.email', 'siedziba@example.pl')
        ->assertJsonPath('headquarters.data.0.phone', '+48600100200')
        ->assertJsonPath('headquarters.data.0.address', 'Długa 5/2, 38-200 Jasło')
        ->assertJsonPath('headquarters.data.0.branches.0.address', null);
});

test('drzewo wszystkich siedzib pokazuje organizację, siedziby, placówki i osoby z każdej organizacji', function (UserRole $role) {
    $f = membersFixture();

    $this->actingAs(User::factory()->create(['role' => $role]))
        ->getJson(route('platform.headquarters.tree', 'shops'))
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonFragment(['name' => 'Siedziba sklepu', 'organization' => 'Org'])
        ->assertJsonFragment(['name' => 'Obca siedziba', 'organization' => 'Inna'])
        ->assertJsonFragment(['name' => 'Lider'])
        ->assertJsonFragment(['name' => 'Obcy kierownik'])
        ->assertJsonMissing(['name' => 'Hurtownia główna']);

    $this->getJson(route('platform.headquarters.tree', 'warehouses'))
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.organization', 'Org')
        ->assertJsonPath('data.0.people.0.name', 'Magazynier');
})->with([UserRole::Admin, UserRole::Manager, UserRole::Employee]);

test('drzewo wszystkich siedzib jest zamknięte dla innych ról i gości, a rodzaj jest walidowany', function () {
    $f = membersFixture();

    foreach ([UserRole::ORGANIZATION_OWNER, UserRole::SHOP_OWNER, UserRole::WAREHOUSE_OWNER, UserRole::SHOP_LEADER, UserRole::SHOP_WORKER] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role, 'organization_id' => $f['org']->id]))
            ->getJson(route('platform.headquarters.tree', 'shops'))->assertForbidden();
    }

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->getJson('/platform/headquarters/branches/tree')->assertNotFound();

    $this->app['auth']->forgetGuards();
    $this->getJson(route('platform.headquarters.tree', 'shops'))->assertUnauthorized();
});

test('strony sklepów i hurtowni z drzewem są dostępne dla administratora', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $this->get('/platform/admin/warehouses/my-headquarters')->assertOk()
        ->assertInertia(fn ($page) => $page->component('platform/admin/warehouses/my-headquarters/index'));
    $this->get('/platform/admin/shops/my-headquarters')->assertOk()
        ->assertInertia(fn ($page) => $page->component('platform/admin/shops/my-headquarters/index'));

    $this->actingAs(User::factory()->create(['role' => UserRole::Manager]))
        ->get('/platform/admin/shops/my-headquarters')->assertForbidden();
});
