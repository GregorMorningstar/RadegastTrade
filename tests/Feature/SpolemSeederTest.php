<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;
use Database\Seeders\SpolemSeeder;

test('seeder Społem Jasło tworzy organizację z dwoma sklepami, sklepem głównym i hurtownią główną, i można go uruchomić ponownie', function () {
    $this->seed(SpolemSeeder::class);
    $this->seed(SpolemSeeder::class);

    $organization = Organization::where('NIP', '6852335266')->sole();
    expect($organization->name)->toBe('Społem Jasło Sp. z o.o.');
    expect(ShopHeadquarter::where('organization_id', $organization->id)->count())->toBe(1);
    expect(WarehouseHeadquarter::where('organization_id', $organization->id)->count())->toBe(1);
    expect(ShopBranch::where('organization_id', $organization->id)->pluck('building_number')->sort()->values()->all())->toBe(['28', '45']);
    expect(User::where('organization_id', $organization->id)->count())->toBe(11);
    expect(User::where('organization_id', $organization->id)->where('role', UserRole::SHOP_WORKER)->groupBy('shop_branch_id')->selectRaw('count(*) as c')->pluck('c')->all())->toBe([3, 3]);
    expect(ShopBranch::first()->barcode)->not->toBeNull();
});
