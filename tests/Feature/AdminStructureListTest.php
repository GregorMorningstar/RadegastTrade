<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;

test('administrator widzi siedziby sklepów i hurtowni oraz placówki wszystkich organizacji', function () {
    $a = Organization::create(['name' => 'Org A', 'NIP' => '5252344078']);
    $b = Organization::create(['name' => 'Org B']);
    $shop = ShopHeadquarter::create(['organization_id' => $a->id, 'name' => 'Sklep A']);
    ShopHeadquarter::create(['organization_id' => $b->id, 'name' => 'Sklep B']);
    WarehouseHeadquarter::create(['organization_id' => $a->id, 'name' => 'Hurtownia A']);
    ShopBranch::create(['organization_id' => $a->id, 'shop_headquarter_id' => $shop->id, 'name' => 'Placówka A']);
    User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $a->id, 'shop_headquarter_id' => $shop->id, 'email' => 'kier@example.com']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $this->get('/platform/admin/shops/headquarters')->assertOk()->assertInertia(fn ($page) => $page
        ->component('platform/admin/shops/headquarters/index')
        ->has('rows', 2)
        ->where('rows.0.organization', 'Org A')
        ->where('rows.0.NIP', '5252344078')
        ->where('rows.0.managers.0.email', 'kier@example.com'));

    $this->get('/platform/admin/warehouses/headquarters')->assertOk()->assertInertia(fn ($page) => $page
        ->has('rows', 1)->where('rows.0.name', 'Hurtownia A'));

    $this->get('/platform/admin/shops/branches')->assertOk()->assertInertia(fn ($page) => $page
        ->has('rows', 1)->where('rows.0.headquarter', 'Sklep A')->where('rows.0.organization', 'Org A'));
});

test('widoki siedzib i placówek w panelu administratora są zamknięte dla innych ról', function (string $url) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Manager]))->get($url)->assertForbidden();
})->with(['/platform/admin/shops/headquarters', '/platform/admin/warehouses/headquarters', '/platform/admin/shops/branches']);

test('OrganizationSeeder tworzy organizację z właścicielem organizacja1@example.pl i można go uruchomić ponownie', function () {
    $this->seed(Database\Seeders\OrganizationSeeder::class);
    $this->seed(Database\Seeders\OrganizationSeeder::class);

    $owner = User::where('email', 'organizacja1@example.pl')->first();

    expect(Organization::count())->toBe(1)
        ->and($owner->role)->toBe(UserRole::ORGANIZATION_OWNER)
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and($owner->organization->NIP)->toBe('6852339117')
        ->and($owner->organization->barcode)->not->toBeNull()
        ->and(ShopHeadquarter::count())->toBe(1)
        ->and(User::where('email', 'test@sklephead.pl')->first()->shop_headquarter_id)->toBe(ShopHeadquarter::first()->id);

    $this->post('/login', ['email' => 'organizacja1@example.pl', 'password' => 'qwer1234'])
        ->assertRedirect('/platform/organization/dashboard');
});

test('OrganizationSeeder tworzy po jednej osobie na rolę w sklepie i w hurtowni, wszystkie z hasłem qwer1234', function () {
    $this->seed(Database\Seeders\OrganizationSeeder::class);

    $expected = [
        'organizacja1@example.pl' => ['organization_owner', '/platform/organization/dashboard'],
        'test@sklephead.pl' => ['shop_owner', '/shop/owner/dashboard'],
        'sklep.lider1@example.pl' => ['shop_leader', '/shop/leader/dashboard'],
        'sklep.pracownik1@example.pl' => ['shop_worker', '/shop/worker/dashboard'],
        'magazyn.kierownik1@example.pl' => ['warehouse_owner', '/warehouse/owner/dashboard'],
        'magazyn.lider1@example.pl' => ['warehouse_leader', '/warehouse/leader/dashboard'],
        'magazyn.pracownik1@example.pl' => ['warehouse_worker', '/warehouse/worker/dashboard'],
    ];

    expect(User::whereIn('email', array_keys($expected))->count())->toBe(7)
        ->and(Organization::count())->toBe(1)
        ->and(ShopHeadquarter::count())->toBe(1)
        ->and(WarehouseHeadquarter::count())->toBe(1)
        ->and(ShopBranch::count())->toBe(1)
        ->and(App\Models\WarehouseBranch::count())->toBe(1);

    $organizationId = Organization::first()->id;

    foreach ($expected as $email => [$role, $dashboard]) {
        $user = User::where('email', $email)->first();

        expect($user->role->value)->toBe($role)
            ->and($user->organization_id)->toBe($organizationId)
            ->and($user->phone)->toStartWith('+48')
            ->and($user->hasVerifiedEmail())->toBeTrue();

        // logowanie hasłem qwer1234 prowadzi na dashboard roli (leader/pracownik mają już placówkę, więc bez formularza)
        $this->post('/login', ['email' => $email, 'password' => 'qwer1234'])->assertRedirect($dashboard);
        $this->post('/logout');
    }

    // leader i pracownik są przypisani do placówek, a kierownik do siedziby
    expect(User::where('email', 'sklep.lider1@example.pl')->first()->shop_branch_id)->toBe(ShopBranch::first()->id)
        ->and(User::where('email', 'magazyn.pracownik1@example.pl')->first()->warehouse_branch_id)->toBe(App\Models\WarehouseBranch::first()->id)
        ->and(User::where('email', 'magazyn.kierownik1@example.pl')->first()->warehouse_headquarter_id)->toBe(WarehouseHeadquarter::first()->id);

    // ponowne uruchomienie niczego nie dubluje
    $this->seed(Database\Seeders\OrganizationSeeder::class);
    expect(User::whereIn('email', array_keys($expected))->count())->toBe(7)->and(ShopBranch::count())->toBe(1);
});
