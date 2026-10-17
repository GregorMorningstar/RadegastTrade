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

test('organizacji z siedzibami nie da się usunąć, dopiero pusta', function () {
    $org = Organization::create(['name' => 'Org']);
    $org->warehouseHeadquarters()->create(['name' => 'H']);
    $org->shopHeadquarters()->create(['name' => 'S']);

    expect(fn () => $org->delete())->toThrow(Illuminate\Database\QueryException::class);
    expect(WarehouseHeadquarter::count())->toBe(1)->and(ShopHeadquarter::count())->toBe(1);

    WarehouseHeadquarter::query()->delete();
    ShopHeadquarter::query()->delete();
    $org->delete();

    expect(Organization::count())->toBe(0);
});

test('baza pilnuje, że placówka należy do tej samej organizacji co jej siedziba, i nie kasuje siedziby z placówkami', function () {
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);
    $hq = $org->shopHeadquarters()->create(['name' => 'S']);

    $branch = fn (int $organizationId) => App\Models\ShopBranch::create(['organization_id' => $organizationId, 'shop_headquarter_id' => $hq->id, 'name' => 'P'.$organizationId]);

    expect(fn () => $branch($other->id))->toThrow(Illuminate\Database\QueryException::class);

    $branch($org->id);
    expect(fn () => $hq->delete())->toThrow(Illuminate\Database\QueryException::class);
});
