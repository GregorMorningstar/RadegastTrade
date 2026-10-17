<?php

use App\Enums\UserRole;
use App\Http\Controllers\Settings\ApiKeyController;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Notifications\ApiKeyNotification;
use App\Notifications\ApiKeyRemovalCodeNotification;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Repositories\EloquentApiKeyRepository;
use App\Services\ApiKeyService;
use App\Services\Contracts\ApiKeyServiceInterface;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * An organization with a shop headquarters (two branches), a second shop headquarters (one branch), a warehouse
 * headquarters (one branch), their people, and a foreign organization.
 *
 * @return array<string, mixed>
 */
function connectorFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);

    $hqA = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep A']);
    $hqB = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep B']);
    $a1 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqA->id, 'name' => 'Placówka A1']);
    $a2 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqA->id, 'name' => 'Placówka A2']);
    $b1 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqB->id, 'name' => 'Placówka B1']);
    $hqW = WarehouseHeadquarter::create(['organization_id' => $org->id, 'name' => 'Hurtownia']);
    $w1 = WarehouseBranch::create(['organization_id' => $org->id, 'warehouse_headquarter_id' => $hqW->id, 'name' => 'Magazyn 1']);
    $foreignHq = ShopHeadquarter::create(['organization_id' => $other->id, 'name' => 'Obcy sklep']);

    $make = fn (UserRole $role, array $extra = [], ?int $orgId = null) => User::factory()->create(['role' => $role, 'organization_id' => $orgId === 0 ? null : ($orgId ?? $org->id), ...$extra]);

    return [
        'org' => $org, 'other' => $other, 'hqA' => $hqA, 'hqB' => $hqB, 'a1' => $a1, 'a2' => $a2, 'b1' => $b1, 'hqW' => $hqW, 'w1' => $w1, 'foreignHq' => $foreignHq,
        'owner' => $make(UserRole::ORGANIZATION_OWNER),
        'managerA' => $make(UserRole::SHOP_OWNER, ['shop_headquarter_id' => $hqA->id]),
        'leaderA1' => $make(UserRole::SHOP_LEADER, ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a1->id]),
        'leaderA2' => $make(UserRole::SHOP_LEADER, ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a2->id]),
        'managerW' => $make(UserRole::WAREHOUSE_OWNER, ['warehouse_headquarter_id' => $hqW->id]),
        'leaderW1' => $make(UserRole::WAREHOUSE_LEADER, ['warehouse_headquarter_id' => $hqW->id, 'warehouse_branch_id' => $w1->id]),
        'worker' => $make(UserRole::SHOP_WORKER, ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a1->id]),
        'warehouseWorker' => $make(UserRole::WAREHOUSE_WORKER, ['warehouse_headquarter_id' => $hqW->id, 'warehouse_branch_id' => $w1->id]),
        'foreignOwner' => $make(UserRole::ORGANIZATION_OWNER, [], $other->id),
        'admin' => User::factory()->create(['role' => UserRole::Admin]),
        'platformManager' => User::factory()->create(['role' => UserRole::Manager]),
        'stray' => $make(UserRole::ORGANIZATION_OWNER, [], 0),
    ];
}

/** URL of the keys of a unit: store / removal-code / destroy share the address. */
function unitUrl(string $name, string $type, string $level, int $unitId): string
{
    return route('api-key.'.$name, ['type' => $type, 'level' => $level, 'unit' => $unitId]);
}

/** Generates the key of a unit as the signed-in person and returns the key from the (faked) e-mail. */
function generatedKey(User $user, string $type, string $level, int $unitId, string $name = 'Kasa 1'): string
{
    $key = null;
    test()->actingAs($user)->post(unitUrl('store', $type, $level, $unitId), ['name' => $name])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ApiKeyNotification::class, function (ApiKeyNotification $notification) use (&$key, $name) {
        if ($notification->connectorName === $name) {
            $key = $notification->key;
        }

        return true;
    });

    return $key;
}

/** Asks for the code that confirms removing the key of a unit and returns it from the (faked) e-mail. */
function removalCodeFor(User $user, string $type, string $level, int $unitId): string
{
    $code = null;
    test()->actingAs($user)->post(unitUrl('removal-code', $type, $level, $unitId))->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ApiKeyRemovalCodeNotification::class, function (ApiKeyRemovalCodeNotification $notification) use (&$code) {
        $code = $notification->code;

        return true;
    });

    return $code;
}

test('klucz konektora powstaje dla jednostki, trafia e-mailem do osoby, a w bazie jest tylko jego skrót', function () {
    Notification::fake();
    $f = connectorFixture();

    $key = generatedKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id, 'Kasa 1');

    $stored = ApiKey::sole();

    expect($key)->toStartWith('rtk_')->and(strlen($key))->toBe(44)
        ->and($stored->key_hash)->toBe(ApiKey::hashOf($key))->not->toBe($key)->and(strlen($stored->key_hash))->toBe(64)
        ->and($stored->prefix)->toBe(substr($key, 0, 12))
        ->and($stored->name)->toBe('Kasa 1')
        ->and($stored->organization_id)->toBe($f['org']->id)
        ->and($stored->shop_branch_id)->toBe($f['a1']->id)
        ->and($stored->shop_headquarter_id)->toBeNull()->and($stored->warehouse_branch_id)->toBeNull()->and($stored->warehouse_headquarter_id)->toBeNull()
        ->and($stored->created_by)->toBe($f['leaderA1']->id)
        ->and($stored->active_unit)->toBe('shops:branches:'.$f['a1']->id)
        ->and($stored->revoked_at)->toBeNull();

    // the e-mail names the unit, the connector and the address; the plain key is nowhere in the table
    Notification::assertSentTo($f['leaderA1'], ApiKeyNotification::class, fn (ApiKeyNotification $n) => $n->unitName === 'Placówka A1' && str_starts_with($n->apiUrl, 'http') && ! str_contains($n->apiUrl, '/api'));
    expect(json_encode(ApiKey::query()->get()->makeVisible('key_hash')->toArray()))->not->toContain($key);
});

test('osoba zarządza kluczami tylko swoich jednostek: właściciel wszystkich, kierownik siedziby jej i placówek, kierownik placówki swojej', function () {
    Notification::fake();
    $f = connectorFixture();
    $try = fn (User $who, string $type, string $level, int $unit) => $this->actingAs($who)->post(unitUrl('store', $type, $level, $unit), ['name' => 'K']);

    // the owner: any unit of the organization
    $try($f['owner'], 'shops', 'headquarters', $f['hqA']->id)->assertRedirect();
    $try($f['owner'], 'warehouses', 'branches', $f['w1']->id)->assertRedirect();

    // a headquarters manager: the headquarters and its branches, nothing else
    $try($f['managerA'], 'shops', 'branches', $f['a1']->id)->assertRedirect();
    $try($f['managerA'], 'shops', 'branches', $f['a2']->id)->assertRedirect();
    $try($f['managerA'], 'shops', 'headquarters', $f['hqB']->id)->assertForbidden();
    $try($f['managerA'], 'shops', 'branches', $f['b1']->id)->assertForbidden();
    $try($f['managerA'], 'warehouses', 'headquarters', $f['hqW']->id)->assertForbidden();

    // a branch leader: only their own branch
    $try($f['leaderA2'], 'shops', 'branches', $f['b1']->id)->assertForbidden();
    $try($f['leaderA2'], 'shops', 'headquarters', $f['hqA']->id)->assertForbidden();
    // (the warehouse branch already got its key from the owner above: the leader is allowed, but one key is the limit)
    $try($f['leaderW1'], 'warehouses', 'branches', $f['w1']->id)->assertSessionHasErrors('api_key');
    $try($f['leaderW1'], 'warehouses', 'headquarters', $f['hqW']->id)->assertForbidden();

    // workers and platform accounts have none
    foreach (['worker', 'warehouseWorker', 'admin', 'platformManager', 'stray'] as $who) {
        $try($f[$who], 'shops', 'branches', $f['a1']->id)->assertForbidden();
    }

    // another organization does not exist for the person: the same answer as for a missing unit
    $try($f['foreignOwner'], 'shops', 'headquarters', $f['hqA']->id)->assertNotFound();
    $try($f['owner'], 'shops', 'headquarters', $f['foreignHq']->id)->assertNotFound();
    $try($f['owner'], 'shops', 'headquarters', 999999)->assertNotFound();
    // a branch id is not a headquarters id: the level matters
    $try($f['owner'], 'shops', 'headquarters', $f['a1']->id + 1000)->assertNotFound();

    expect(ApiKey::count())->toBe(4);
});

test('jednostka ma jeden aktywny klucz: nowy dopiero po usunięciu starego, klucze jednostek są niezależne', function () {
    Notification::fake();
    $f = connectorFixture();

    generatedKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id);

    // a second key of the same unit, also from someone else who manages it, is refused
    $this->post(unitUrl('store', 'shops', 'branches', $f['a1']->id), ['name' => 'Drugi'])->assertSessionHasErrors('api_key');
    $this->actingAs($f['managerA'])->post(unitUrl('store', 'shops', 'branches', $f['a1']->id), ['name' => 'Drugi'])->assertSessionHasErrors('api_key');
    expect(ApiKey::count())->toBe(1);

    // another unit has its own key
    $this->post(unitUrl('store', 'shops', 'branches', $f['a2']->id), ['name' => 'Inny'])->assertSessionHasNoErrors();
    expect(ApiKey::whereNull('revoked_at')->count())->toBe(2);

    // the database allows one active key per unit as well
    $row = ApiKey::where('shop_branch_id', $f['a1']->id)->first();
    expect(fn () => ApiKey::query()->insert(['organization_id' => $f['org']->id, 'shop_branch_id' => $f['a1']->id, 'active_unit' => $row->active_unit, 'name' => 'x', 'prefix' => 'rtk_xxxxxxxx', 'key_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('usunięcie klucza wymaga kodu z e-maila osoby, która o niego prosiła; potem jednostka dostaje nowy, inny klucz', function () {
    Notification::fake();
    $f = connectorFixture();
    $first = generatedKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id);
    $url = unitUrl('destroy', 'shops', 'branches', $f['a1']->id);

    // no code asked for yet, a missing code
    $this->delete($url, ['code' => '123456'])->assertSessionHasErrors('code');
    $this->delete($url)->assertSessionHasErrors('code');

    $code = removalCodeFor($f['leaderA1'], 'shops', 'branches', $f['a1']->id);
    expect($code)->toMatch('/^\d{6}$/')->and(ApiKey::sole()->removal_code_hash)->toBe(ApiKey::hashOf($code))->not->toBe($code);

    // the code does not work for someone else who manages the unit
    $this->actingAs($f['managerA'])->delete($url, ['code' => $code])->assertSessionHasErrors('code');
    $wrong = $code === '000000' ? '111111' : '000000';
    $this->actingAs($f['leaderA1'])->delete($url, ['code' => $wrong])->assertSessionHasErrors('code');
    expect(ApiKey::whereNull('revoked_at')->count())->toBe(1);

    $this->delete($url, ['code' => $code])->assertRedirect()->assertSessionHasNoErrors();
    $revoked = ApiKey::sole();
    expect($revoked->revoked_at)->not->toBeNull()->and($revoked->revoked_by)->toBe($f['leaderA1']->id)->and($revoked->revoker->is($f['leaderA1']))->toBeTrue()->and($revoked->active_unit)->toBeNull()->and($revoked->removal_code_hash)->toBeNull();

    $second = generatedKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id, 'Kasa 2');
    expect($second)->not->toBe($first)->and(ApiKey::whereNull('revoked_at')->sole()->key_hash)->toBe(ApiKey::hashOf($second));
});

test('kod usunięcia wygasa po 15 minutach, po pięciu błędach przestaje działać, a nowy kod unieważnia poprzedni', function () {
    Notification::fake();
    $f = connectorFixture();
    generatedKey($f['owner'], 'shops', 'headquarters', $f['hqA']->id);
    $url = unitUrl('destroy', 'shops', 'headquarters', $f['hqA']->id);

    $code = removalCodeFor($f['owner'], 'shops', 'headquarters', $f['hqA']->id);
    $this->travel(16)->minutes();
    $this->delete($url, ['code' => $code])->assertSessionHasErrors('code');
    $this->travelBack();
    expect(ApiKey::whereNull('revoked_at')->count())->toBe(1);

    $fresh = removalCodeFor($f['owner'], 'shops', 'headquarters', $f['hqA']->id);
    $wrong = $fresh === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $attempt) {
        $this->delete($url, ['code' => $wrong])->assertSessionHasErrors('code');
    }

    // the code is cancelled after the fifth wrong one: also the right one no longer works
    $this->delete($url, ['code' => $fresh])->assertSessionHasErrors('code');
    expect(ApiKey::whereNull('revoked_at')->count())->toBe(1);

    $again = removalCodeFor($f['owner'], 'shops', 'headquarters', $f['hqA']->id);
    $this->delete($url, ['code' => $again])->assertSessionHasNoErrors();
    expect(ApiKey::whereNull('revoked_at')->count())->toBe(0);

    // a unit without a key has nothing to remove
    $this->post(unitUrl('removal-code', 'shops', 'headquarters', $f['hqA']->id))->assertSessionHasErrors('api_key');
});

test('strona kluczy pokazuje jednostki osoby i stan kluczy, nigdy samego klucza ani kodu', function () {
    Notification::fake();
    $f = connectorFixture();
    $names = fn (array $units) => collect($units)->pluck('name')->all();
    $page = fn (User $who) => $this->actingAs($who)->get(route('api-key.edit'))->assertOk()->viewData('page')['props']['apiKeys'];

    expect($names($page($f['owner'])['units']))->toBe(['Sklep A', 'Placówka A1', 'Placówka A2', 'Sklep B', 'Placówka B1', 'Hurtownia', 'Magazyn 1'])
        ->and($names($page($f['managerA'])['units']))->toBe(['Sklep A', 'Placówka A1', 'Placówka A2'])
        ->and($names($page($f['leaderA1'])['units']))->toBe(['Placówka A1'])
        ->and($names($page($f['leaderW1'])['units']))->toBe(['Magazyn 1'])
        ->and($page($f['leaderA1'])['units'][0]['key'])->toBeNull();

    foreach (['worker', 'admin', 'platformManager', 'stray', 'warehouseWorker'] as $who) {
        $this->actingAs($f[$who])->get(route('api-key.edit'))->assertForbidden();
    }

    $key = generatedKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id);
    $stored = ApiKey::sole();
    $code = removalCodeFor($f['leaderA1'], 'shops', 'branches', $f['a1']->id);

    $response = $this->get(route('api-key.edit'))->assertOk();
    $unit = $response->viewData('page')['props']['apiKeys']['units'][0];
    expect($unit['key'])->toMatchArray(['name' => 'Kasa 1', 'prefix' => $stored->prefix, 'removal_pending' => true])
        ->and($response->getContent())->not->toContain($key)->not->toContain($code)->not->toContain($stored->key_hash)->not->toContain((string) $stored->fresh()->removal_code_hash);

    // the code waits only for the person who asked for it
    expect($page($f['managerA'])['units'][1]['key']['removal_pending'])->toBeFalse()
        ->and($page($f['owner'])['units'][1]['key']['removal_pending'])->toBeFalse();
});

test('konektor łączy się kluczem: ping pokazuje jego jednostkę, a zły, odwołany i brakujący klucz są odrzucane', function () {
    Notification::fake();
    $f = connectorFixture();
    $key = generatedKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id, 'Kasa 1');
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/ping', ['Authorization' => 'Bearer '.$key])->assertOk()
        ->assertJsonPath('ok', true)->assertJsonPath('organization', 'Org')
        ->assertJsonPath('unit.name', 'Placówka A1')->assertJsonPath('unit.type', 'Sklep')->assertJsonPath('unit.level', 'Placówka')
        ->assertJsonPath('key.name', 'Kasa 1')->assertJsonMissingPath('key_hash')->assertJsonStructure(['server_time']);

    // the other header works too, and the use is noted
    $this->getJson('/api/ping', ['X-Api-Key' => $key])->assertOk();
    expect(ApiKey::sole()->last_used_at)->not->toBeNull();

    foreach ([['Authorization' => 'Bearer rtk_'.str_repeat('a', 40)], ['Authorization' => 'Bearer '.$key.'x'], ['Authorization' => 'Bearer zly'], []] as $headers) {
        $this->getJson('/api/ping', $headers)->assertUnauthorized()->assertJsonPath('message', 'Nieprawidłowy lub nieaktywny klucz API.');
    }

    // a signed-in person is not a connector
    $this->actingAs($f['owner'])->getJson('/api/ping')->assertUnauthorized();

    // the key removed: the connector is out
    $this->actingAs($f['leaderA1']);
    $code = removalCodeFor($f['leaderA1'], 'shops', 'branches', $f['a1']->id);
    $this->delete(unitUrl('destroy', 'shops', 'branches', $f['a1']->id), ['code' => $code]);
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/ping', ['Authorization' => 'Bearer '.$key])->assertUnauthorized();
});

test('zgadywanie kluczy jest ograniczone: po 20 nieudanych próbach z adresu odpowiedź to 429', function () {
    RateLimiter::clear('api-key-failures:127.0.0.1');

    foreach (range(1, 20) as $attempt) {
        $this->getJson('/api/ping', ['Authorization' => 'Bearer rtk_'.str_repeat('b', 40)])->assertUnauthorized();
    }

    $this->getJson('/api/ping', ['Authorization' => 'Bearer rtk_'.str_repeat('c', 40)])->assertStatus(429)->assertHeader('Retry-After');
});

test('klucz nie przypisuje się masowo, a organizacji i jednostki nie da się podać w żądaniu', function () {
    Notification::fake();
    $f = connectorFixture();
    $other = Organization::create(['name' => 'Trzecia']);

    $this->actingAs($f['leaderA1'])->post(unitUrl('store', 'shops', 'branches', $f['a1']->id), ['name' => 'K', 'organization_id' => $other->id, 'shop_branch_id' => $f['a2']->id])->assertRedirect();

    $stored = ApiKey::sole();
    expect($stored->organization_id)->toBe($f['org']->id)->and($stored->shop_branch_id)->toBe($f['a1']->id)
        ->and((new ApiKey)->isFillable('organization_id'))->toBeFalse()->and((new ApiKey)->isFillable('shop_branch_id'))->toBeFalse()->and((new ApiKey)->isFillable('created_by'))->toBeFalse();
});

test('menu: link konfiguracji i rodzaj jednostki dla właściciela, sklepu i hurtowni', function () {
    $f = connectorFixture();

    foreach (['owner' => ['Właściciel', '/platform/organization'], 'managerA' => ['Sklep', '/shop/owner'], 'leaderA1' => ['Sklep', '/shop/leader'], 'managerW' => ['Hurtownia', '/warehouse/owner'], 'leaderW1' => ['Hurtownia', '/warehouse/leader']] as $who => [$label, $base]) {
        $this->actingAs($f[$who])->get($base.'/products')->assertOk()
            ->assertInertia(fn ($page) => $page->where('apiKeyAllowed', true)->where('unitLabel', $label));
    }

    $this->actingAs($f['worker'])->get('/shop/worker/products')->assertInertia(fn ($page) => $page->where('apiKeyAllowed', false)->where('unitLabel', 'Sklep'));
    $this->actingAs($f['warehouseWorker'])->get('/warehouse/worker/products')->assertInertia(fn ($page) => $page->where('apiKeyAllowed', false)->where('unitLabel', 'Hurtownia'));
});

test('menu: sekcja jednostki pokazuje siedziby i placówki osoby, a właściciel widzi wszystkie', function () {
    $f = connectorFixture();
    $units = fn (User $who, string $url) => collect($this->actingAs($who)->get($url)->assertOk()->viewData('page')['props']['units'])->map(fn ($unit) => $unit['level'].':'.$unit['name'])->all();

    expect($units($f['owner'], '/platform/organization/products'))->toBe(['headquarters:Sklep A', 'branch:Placówka A1', 'branch:Placówka A2', 'headquarters:Sklep B', 'branch:Placówka B1', 'headquarters:Hurtownia', 'branch:Magazyn 1'])
        ->and($units($f['managerA'], '/shop/owner/products'))->toBe(['headquarters:Sklep A', 'branch:Placówka A1', 'branch:Placówka A2'])
        ->and($units($f['leaderA1'], '/shop/leader/products'))->toBe(['headquarters:Sklep A', 'branch:Placówka A1']);
});

test('serwis, repozytorium i kontroler kluczy API są powiązane konstruktorami', function () {
    expect(app(ApiKeyServiceInterface::class))->toBeInstanceOf(ApiKeyService::class)
        ->and(app(ApiKeyRepositoryInterface::class))->toBeInstanceOf(EloquentApiKeyRepository::class)
        ->and((new ReflectionClass(ApiKeyService::class))->getConstructor()->getParameters()[0]->getType()->getName())->toBe(ApiKeyRepositoryInterface::class)
        ->and((new ReflectionClass(ApiKeyController::class))->getConstructor()->getParameters()[0]->getType()->getName())->toBe(ApiKeyServiceInterface::class);
});

/** The key of a unit (generated as a person who manages it), as a connector holds it. */
function connectorKey(User $manager, string $type, string $level, int $unitId): string
{
    $key = generatedKey($manager, $type, $level, $unitId);
    app('auth')->forgetGuards();

    return $key;
}

function towary(array $ids): array
{
    return ['table' => 'dbo.Towary', 'syncedAtUtc' => '2026-10-17T10:00:00Z', 'rowCount' => count($ids), 'rows' => array_map(fn ($id) => ['Id' => $id, 'Kod' => 'K'.$id, 'Nazwa' => 'Produkt '.$id, 'Cena' => 19.99], $ids)];
}

test('konektor wysyła tabelę zgodnie z kontraktem ApiConnector, a dane trafiają do jednostki z klucza', function () {
    Notification::fake();
    $f = connectorFixture();
    $key = connectorKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id);

    $this->postJson('/api/sync/data/dbo_Towary', towary([1, 2, 3]), ['X-API-KEY' => $key])->assertOk()->assertJson(['table' => 'dbo_Towary', 'received' => 3]);

    $row = App\Models\SyncedRow::where('external_id', '2')->sole();
    expect(App\Models\SyncedRow::count())->toBe(3)
        ->and($row->organization_id)->toBe($f['org']->id)->and($row->shop_branch_id)->toBe($f['a1']->id)->and($row->unit_ref)->toBe('shops:branches:'.$f['a1']->id)
        ->and($row->table_name)->toBe('dbo_Towary')->and($row->data)->toMatchArray(['Kod' => 'K2', 'Nazwa' => 'Produkt 2'])
        ->and($row->synced_at->toIso8601String())->toStartWith('2026-10-17T10:00:00');

    // sent again: updated, not duplicated; the Bearer form works too
    $changed = towary([2]);
    $changed['rows'][0]['Cena'] = 25.5;
    $this->postJson('/api/sync/data/dbo_Towary', $changed, ['Authorization' => 'Bearer '.$key])->assertOk();
    expect(App\Models\SyncedRow::count())->toBe(3)->and(App\Models\SyncedRow::where('external_id', '2')->sole()->data['Cena'])->toBe(25.5);
});

test('dane jednostki są odseparowane: ten sam Id w dwóch jednostkach to dwa wiersze, a cudzy klucz niczego nie nadpisze ani nie odczyta', function () {
    Notification::fake();
    $f = connectorFixture();
    $keyA1 = connectorKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id);
    $keyA2 = connectorKey($f['leaderA2'], 'shops', 'branches', $f['a2']->id);
    $keyW1 = connectorKey($f['leaderW1'], 'warehouses', 'branches', $f['w1']->id);

    $this->postJson('/api/sync/data/dbo_Towary', towary([1, 2]), ['X-API-KEY' => $keyA1])->assertOk();

    $other = towary([1]);
    $other['rows'][0]['Nazwa'] = 'Z INNEJ PLACÓWKI';
    $this->postJson('/api/sync/data/dbo_Towary', $other, ['X-API-KEY' => $keyA2])->assertOk();
    $this->postJson('/api/sync/data/dbo_Towary', $other, ['X-API-KEY' => $keyW1])->assertOk();

    // three units, the same source id 1: three rows, the data of the first unit untouched
    expect(App\Models\SyncedRow::where('external_id', '1')->count())->toBe(3)
        ->and(App\Models\SyncedRow::where('unit_ref', 'shops:branches:'.$f['a1']->id)->where('external_id', '1')->sole()->data['Nazwa'])->toBe('Produkt 1');

    $repository = app(App\Repositories\Contracts\SyncedDataRepositoryInterface::class);
    $a1 = 'shops:branches:'.$f['a1']->id;

    // reading is for one unit: the other units' rows are not there, and neither are they for another organization
    expect($repository->rows($f['org']->id, $a1, 'dbo_Towary', 1, 50)->pluck('external_id')->all())->toBe(['1', '2'])
        ->and($repository->tables($f['org']->id, $a1)->pluck('rows', 'table_name')->all())->toBe(['dbo_Towary' => 2])
        ->and($repository->rows($f['other']->id, $a1, 'dbo_Towary', 1, 50)->total())->toBe(0)
        ->and($repository->tables($f['org']->id, 'shops:branches:'.$f['b1']->id))->toHaveCount(0);
});

test('jednostki ani organizacji nie da się podać w danych: takie żądanie jest odrzucane, a pola w wierszu to zwykłe dane', function () {
    Notification::fake();
    $f = connectorFixture();
    $key = connectorKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id);

    foreach (['organization_id' => $f['other']->id, 'unit' => 'shops:branches:'.$f['a2']->id, 'placowka_id' => $f['a2']->id] as $field => $value) {
        $this->postJson('/api/sync/data/dbo_Towary', [...towary([1]), $field => $value], ['X-API-KEY' => $key])->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    expect(App\Models\SyncedRow::count())->toBe(0);

    // a column called placowka_id inside a row is just a column of the source table
    $payload = towary([7]);
    $payload['rows'][0]['placowka_id'] = $f['a2']->id;
    $this->postJson('/api/sync/data/dbo_Towary', $payload, ['X-API-KEY' => $key])->assertOk();

    $row = App\Models\SyncedRow::sole();
    expect($row->shop_branch_id)->toBe($f['a1']->id)->and($row->organization_id)->toBe($f['org']->id)->and($row->data['placowka_id'])->toBe($f['a2']->id);
});

test('tabela bez kolumny Id jest rozpoznawana po treści wiersza, a zła nazwa lub dane są odrzucane', function () {
    Notification::fake();
    $f = connectorFixture();
    $key = connectorKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id);
    $headers = ['X-API-KEY' => $key];

    $payload = ['table' => 'dbo.Ceny', 'rows' => [['Kod' => 'A', 'Cena' => 1], ['Kod' => 'B', 'Cena' => 2]]];
    $this->postJson('/api/sync/data/dbo_Ceny', $payload, $headers)->assertOk()->assertJson(['received' => 2]);
    $this->postJson('/api/sync/data/dbo_Ceny', $payload, $headers)->assertOk();
    expect(App\Models\SyncedRow::count())->toBe(2);

    // an empty table is fine, the table of the data has to be the table of the address, the rows have to be there
    $this->postJson('/api/sync/data/dbo_Puste', ['table' => 'dbo.Puste', 'rows' => []], $headers)->assertOk()->assertJson(['received' => 0]);
    $this->postJson('/api/sync/data/dbo_Ceny', towary([1]), $headers)->assertUnprocessable()->assertJsonValidationErrors('table');
    $this->postJson('/api/sync/data/dbo_Ceny', ['table' => 'dbo.Ceny'], $headers)->assertUnprocessable()->assertJsonValidationErrors('rows');
    $this->postJson('/api/sync/data/dbo_Ceny', ['table' => 'dbo.Ceny', 'rows' => ['nie wiersz']], $headers)->assertUnprocessable();
    $this->assertContains($this->postJson('/api/sync/data/dbo.Ceny;drop', ['table' => 'dbo.Ceny', 'rows' => []], $headers)->status(), [404, 405]);
    $this->postJson('/api/sync/data/dbo_Ceny', ['table' => 'dbo.Ceny', 'rows' => [array_fill(0, 301, 1)]], $headers)->assertUnprocessable();
});

test('wysyłka danych wymaga aktywnego klucza: bez klucza, z cudzym lub odwołanym jest 401 i nic się nie zapisuje', function () {
    Notification::fake();
    $f = connectorFixture();
    $key = connectorKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id);

    $this->postJson('/api/sync/data/dbo_Towary', towary([1]))->assertUnauthorized();
    $this->postJson('/api/sync/data/dbo_Towary', towary([1]), ['X-API-KEY' => 'rtk_'.str_repeat('z', 40)])->assertUnauthorized();
    // a signed-in person without a key is not a connector
    $this->actingAs($f['owner'])->postJson('/api/sync/data/dbo_Towary', towary([1]))->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    expect(App\Models\SyncedRow::count())->toBe(0);

    $code = removalCodeFor($f['leaderA1'], 'shops', 'branches', $f['a1']->id);
    $this->delete(unitUrl('destroy', 'shops', 'branches', $f['a1']->id), ['code' => $code]);
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/sync/data/dbo_Towary', towary([1]), ['X-API-KEY' => $key])->assertUnauthorized();
    expect(App\Models\SyncedRow::count())->toBe(0);
});

test('serwis i repozytorium danych konektora są powiązane konstruktorami', function () {
    expect(app(App\Services\Contracts\SyncServiceInterface::class))->toBeInstanceOf(App\Services\SyncService::class)
        ->and(app(App\Repositories\Contracts\SyncedDataRepositoryInterface::class))->toBeInstanceOf(App\Repositories\EloquentSyncedDataRepository::class)
        ->and((new ReflectionClass(App\Services\SyncService::class))->getConstructor()->getParameters()[0]->getType()->getName())->toBe(App\Repositories\Contracts\SyncedDataRepositoryInterface::class)
        ->and((new ReflectionClass(App\Http\Controllers\Api\SyncController::class))->getConstructor()->getParameters()[0]->getType()->getName())->toBe(App\Services\Contracts\SyncServiceInterface::class);
});

test('klucz jest zapisany zaszyfrowany i można go pokazać ponownie po potwierdzeniu hasłem, tylko osobie zarządzającej jednostką', function () {
    Notification::fake();
    $f = connectorFixture();
    $key = generatedKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id);
    $url = unitUrl('reveal', 'shops', 'branches', $f['a1']->id);

    // in the database it is neither the key nor anything that contains it, but it can be decrypted
    $raw = DB::table('api_keys')->value('key_encrypted');
    expect($raw)->not->toBeNull()->not->toContain($key)->and(Illuminate\Support\Facades\Crypt::decryptString($raw))->toBe($key);

    // a wrong or missing password shows nothing
    $this->postJson($url, ['password' => 'zle-haslo'])->assertUnprocessable()->assertJsonValidationErrors('password')->assertJsonMissing(['key' => $key]);
    $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('password');
    expect(ApiKey::sole()->revealed_at)->toBeNull();

    // the right password shows the key, never cached, and the showing is noted
    $this->postJson($url, ['password' => 'password'])->assertOk()->assertJson(['key' => $key])->assertHeader('Cache-Control', 'no-store, private');
    $stored = ApiKey::sole();
    expect($stored->revealed_at)->not->toBeNull()->and($stored->revealed_by)->toBe($f['leaderA1']->id);

    // the same key still works for the connector
    app('auth')->forgetGuards();
    $this->getJson('/api/ping', ['X-API-KEY' => $key])->assertOk();

    // someone who does not manage the unit, a worker, another organization: no
    $this->actingAs($f['leaderA2'])->postJson($url, ['password' => 'password'])->assertForbidden();
    $this->actingAs($f['worker'])->postJson($url, ['password' => 'password'])->assertForbidden();
    $this->actingAs($f['foreignOwner'])->postJson($url, ['password' => 'password'])->assertNotFound();
    // the manager of the headquarters and the owner do manage it
    $this->actingAs($f['managerA'])->postJson($url, ['password' => 'password'])->assertOk()->assertJson(['key' => $key]);
    $this->actingAs($f['owner'])->postJson($url, ['password' => 'password'])->assertOk();
});

test('strona kluczy mówi, czy klucz można pokazać, ale go nie zawiera; po usunięciu kopia znika', function () {
    Notification::fake();
    $f = connectorFixture();
    $key = generatedKey($f['leaderA1'], 'shops', 'branches', $f['a1']->id);

    $response = $this->get(route('api-key.edit'))->assertOk();
    expect($response->viewData('page')['props']['apiKeys']['units'][0]['key']['can_reveal'])->toBeTrue()
        ->and($response->getContent())->not->toContain($key)->not->toContain((string) DB::table('api_keys')->value('key_encrypted'));

    // a key without a saved copy (generated earlier) cannot be shown: it has to be removed and made again
    DB::table('api_keys')->update(['key_encrypted' => null]);
    $this->postJson(unitUrl('reveal', 'shops', 'branches', $f['a1']->id), ['password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('api_key');
    expect($this->get(route('api-key.edit'))->viewData('page')['props']['apiKeys']['units'][0]['key']['can_reveal'])->toBeFalse();

    // the copy is cleared when the key is removed, and a removed key cannot be shown
    $second = connectorKey($f['leaderA2'], 'shops', 'branches', $f['a2']->id);
    $this->actingAs($f['leaderA2']);
    $code = removalCodeFor($f['leaderA2'], 'shops', 'branches', $f['a2']->id);
    $this->delete(unitUrl('destroy', 'shops', 'branches', $f['a2']->id), ['code' => $code]);
    expect(ApiKey::where('shop_branch_id', $f['a2']->id)->sole()->getRawOriginal('key_encrypted'))->toBeNull();
    $this->postJson(unitUrl('reveal', 'shops', 'branches', $f['a2']->id), ['password' => 'password'])->assertUnprocessable();
    expect($second)->toStartWith('rtk_');
});
