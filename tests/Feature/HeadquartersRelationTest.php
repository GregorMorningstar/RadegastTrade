<?php

use App\Models\Organization;
use App\Models\ShopHeadquarter;
use App\Models\WarehouseHeadquarter;
use Illuminate\Database\UniqueConstraintViolationException;

test('organizacja ma wiele siedzib, a siedziba należy do jednej organizacji', function () {
    $org = Organization::create(['name' => 'Org A']);

    $org->warehouseHeadquarters()->create(['name' => 'Hurtownia 1']);
    $org->warehouseHeadquarters()->create(['name' => 'Hurtownia 2']);
    $org->shopHeadquarters()->create(['name' => 'Sklep 1']);
    $org->shopHeadquarters()->create(['name' => 'Sklep 2']);

    expect($org->warehouseHeadquarters)->toHaveCount(2)
        ->and($org->shopHeadquarters)->toHaveCount(2)
        ->and(WarehouseHeadquarter::first()->organization->is($org))->toBeTrue()
        ->and(ShopHeadquarter::first()->organization->is($org))->toBeTrue();
});

test('ta sama nazwa siedziby może wystąpić w różnych organizacjach, ale nie w jednej', function () {
    $a = Organization::create(['name' => 'A']);
    $b = Organization::create(['name' => 'B']);

    $a->shopHeadquarters()->create(['name' => 'Centrala']);
    $b->shopHeadquarters()->create(['name' => 'Centrala']);

    expect(ShopHeadquarter::count())->toBe(2);

    $a->shopHeadquarters()->create(['name' => 'Centrala']);
})->throws(UniqueConstraintViolationException::class);

test('usunięcie organizacji usuwa jej siedziby', function () {
    $org = Organization::create(['name' => 'Org']);
    $org->warehouseHeadquarters()->create(['name' => 'H']);
    $org->shopHeadquarters()->create(['name' => 'S']);

    $org->delete();

    expect(WarehouseHeadquarter::count())->toBe(0)
        ->and(ShopHeadquarter::count())->toBe(0);
});
