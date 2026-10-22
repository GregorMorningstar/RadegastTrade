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
        ->and($org->barcode)->toStartWith('2101')
        ->and($warehouse->barcode)->toStartWith('2201')
        ->and($shop->barcode)->toStartWith('2301');

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

test('schemat prefiksów: 4 cyfry z zakresu 2000-2999, każdy rodzaj rekordu ma własny, rodziny zgodne z opisem', function () {
    $prefixes = config('barcode.prefixes');

    foreach ($prefixes as $key => $prefix) {
        expect(Barcode::prefix($key))->toBe($prefix)->and($prefix)->toMatch('/^2\d{3}$/');
    }

    // no two kinds share a prefix (the scanner recognises the record by it)
    expect(collect($prefixes)->unique())->toHaveCount(count($prefixes));

    // the first two digits are the family
    $families = ['user' => '20', 'organization' => '21', 'warehouse_headquarter' => '22', 'warehouse_branch' => '22', 'shop_headquarter' => '23', 'shop_branch' => '23',
        'product' => '24', 'product_category' => '24', 'task' => '25', 'offer' => '26', 'order' => '26'];

    foreach ($families as $key => $family) {
        expect(substr($prefixes[$key], 0, 2))->toBe($family, "rodzina prefiksu [$key]");
    }

    // every kind of the config has its family listed here (a new kind has to be placed in the scheme on purpose)
    expect(array_keys($prefixes))->toEqualCanonicalizing(array_keys($families));
});

test('placówki, zadania i oferty dostają kody ze swoimi prefiksami i są rozpoznawane po kodzie', function () {
    $org = Organization::create(['name' => 'Org']);
    $shopHq = $org->shopHeadquarters()->create(['name' => 'S']);
    $warehouseHq = $org->warehouseHeadquarters()->create(['name' => 'H']);
    $shopBranch = App\Models\ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $shopHq->id, 'name' => 'Sklep 1']);
    $warehouseBranch = App\Models\WarehouseBranch::create(['organization_id' => $org->id, 'warehouse_headquarter_id' => $warehouseHq->id, 'name' => 'Magazyn 1']);
    $task = (new App\Models\Task)->forceFill(['organization_id' => $org->id, 'title' => 'Zadanie', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
    $task->save();
    $offer = App\Models\Offer::create(['organization_id' => $org->id, 'number' => 'OF/1', 'unit_ref' => 'warehouses:headquarters:'.$warehouseHq->id, 'warehouse_headquarter_id' => $warehouseHq->id]);

    $expected = ['2202' => $warehouseBranch, '2302' => $shopBranch, '2501' => $task, '2601' => $offer, '2201' => $warehouseHq, '2301' => $shopHq, '2101' => $org];

    foreach ($expected as $prefix => $model) {
        expect($model->fresh()->barcode)->toStartWith((string) $prefix)->and(Barcode::isValidEan13($model->fresh()->barcode))->toBeTrue()
            ->and(Barcode::resolve($model->fresh()->barcode)?->is($model))->toBeTrue();
    }

    expect(collect($expected)->map(fn ($model) => $model->fresh()->barcode)->unique())->toHaveCount(count($expected));
});
