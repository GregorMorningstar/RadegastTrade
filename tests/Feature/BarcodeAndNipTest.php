<?php

use App\Models\Organization;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;
use App\Support\Barcode;
use Illuminate\Validation\ValidationException;

test('każda encja dostaje poprawny EAN-13 z własnym prefiksem z konfiguracji', function () {
    config()->set('barcode.prefixes.user', '2011');

    $user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $warehouse = $org->warehouseHeadquarters()->create(['name' => 'H']);
    $shop = $org->shopHeadquarters()->create(['name' => 'S']);

    expect($user->barcode)->toStartWith('2011')
        ->and($org->barcode)->toStartWith('2002')
        ->and($warehouse->barcode)->toStartWith('2003')
        ->and($shop->barcode)->toStartWith('2004');

    foreach ([$user, $org, $warehouse, $shop] as $model) {
        expect(Barcode::isValidEan13($model->barcode))->toBeTrue();
    }

    expect(collect([$user, $org, $warehouse, $shop])->pluck('barcode')->unique())->toHaveCount(4);
});

test('prefiks spoza zakresu 2000-2999 jest odrzucany', function () {
    config()->set('barcode.prefixes.user', '5901');

    Barcode::ean13('user', 1);
})->throws(InvalidArgumentException::class);

test('produkt od producenta nie może używać prefiksu systemowego', function () {
    $rules = (new App\Http\Requests\Products\StoreProductRequest)->rules();

    $validate = fn (string $code) => validator(['name' => 'X', 'barcode' => $code], $rules)->passes();

    $system = Barcode::ean13('user', 5);            // zaczyna się od prefiksu użytkowników
    $manufacturer = '5901234123457';                 // prawdziwy EAN producenta

    expect($validate($system))->toBeFalse()
        ->and($validate($manufacturer))->toBeTrue();
});

test('resolve rozpoznaje rodzaj kodu po prefiksie', function () {
    $user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);

    expect(Barcode::resolve($user->barcode)->is($user))->toBeTrue()
        ->and(Barcode::resolve($org->barcode)->is($org))->toBeTrue()
        ->and(Barcode::resolve('5901234123457'))->toBeNull(); // towar od producenta: poza systemem
});

test('dwie organizacje nie mogą mieć tego samego NIP', function () {
    Organization::create(['name' => 'A', 'NIP' => '1234567890']);
    Organization::create(['name' => 'B', 'NIP' => '1234567890']);
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);

test('placówki mogą mieć NIP organizacji, ale nie NIP innej organizacji', function () {
    $a = Organization::create(['name' => 'A', 'NIP' => '1111111111']);
    $b = Organization::create(['name' => 'B', 'NIP' => '2222222222']);

    // ten sam NIP co własna organizacja – dozwolone, także w wielu placówkach naraz
    $a->shopHeadquarters()->create(['name' => 'S1', 'NIP' => '1111111111']);
    $a->warehouseHeadquarters()->create(['name' => 'H1', 'NIP' => '1111111111']);
    $a->shopHeadquarters()->create(['name' => 'S2', 'NIP' => '1111111111']);

    expect(ShopHeadquarter::where('NIP', '1111111111')->count())->toBe(2)
        ->and($a->shopHeadquarters()->first()->effectiveNip())->toBe('1111111111');

    // NIP innej organizacji – zabronione
    expect(fn () => $a->warehouseHeadquarters()->create(['name' => 'H2', 'NIP' => '2222222222']))
        ->toThrow(ValidationException::class);

    // własny, nieużywany NIP placówki – dozwolone
    $b->warehouseHeadquarters()->create(['name' => 'H3', 'NIP' => '3333333333']);

    // organizacja nie może przejąć NIP-u, którego używa placówka innej organizacji
    expect(fn () => Organization::create(['name' => 'C', 'NIP' => '3333333333']))
        ->toThrow(ValidationException::class);

    expect(WarehouseHeadquarter::where('NIP', '2222222222')->count())->toBe(0);
});

test('placówka bez NIP dziedziczy NIP organizacji', function () {
    $org = Organization::create(['name' => 'A', 'NIP' => '1111111111']);
    $hq = $org->shopHeadquarters()->create(['name' => 'S']);

    expect($hq->NIP)->toBeNull()
        ->and($hq->effectiveNip())->toBe('1111111111');
});
