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
