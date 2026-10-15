<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;

/**
 * Organization with an owner, two shop headquarters (each with a manager), a branch with a leader, a free worker,
 * a warehouse person and a foreign organization.
 *
 * @return array<string, mixed>
 */
function managerFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);

    $owner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $org->id]);

    $a = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Siedziba A']);
    $b = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Siedziba B']);
    $branch = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $b->id, 'name' => 'Placówka B1']);

    $managerA = User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $org->id, 'shop_headquarter_id' => $a->id, 'name' => 'Kierownik A']);
    $managerB = User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $org->id, 'shop_headquarter_id' => $b->id, 'name' => 'Kierownik B']);
    $leader = User::factory()->create(['role' => UserRole::SHOP_LEADER, 'organization_id' => $org->id, 'shop_headquarter_id' => $b->id, 'shop_branch_id' => $branch->id, 'name' => 'Lider B1']);
    $worker = User::factory()->create(['role' => UserRole::SHOP_WORKER, 'organization_id' => $org->id, 'name' => 'Wolny pracownik']);

    $warehouse = WarehouseHeadquarter::create(['organization_id' => $org->id, 'name' => 'Hurtownia']);
    $warehouseManager = User::factory()->create(['role' => UserRole::WAREHOUSE_OWNER, 'organization_id' => $org->id, 'warehouse_headquarter_id' => $warehouse->id, 'name' => 'Kierownik hurtowni']);

    $foreignHq = ShopHeadquarter::create(['organization_id' => $other->id, 'name' => 'Obca']);
    $foreign = User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $other->id, 'shop_headquarter_id' => $foreignHq->id, 'name' => 'Obcy']);
    $foreignOwner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $other->id]);

    return compact('org', 'owner', 'a', 'b', 'branch', 'managerA', 'managerB', 'leader', 'worker', 'warehouse', 'warehouseManager', 'foreignHq', 'foreign', 'foreignOwner');
}

test('lista osób do przypisania zawiera ostrzeżenia o skutkach zmiany', function () {
    $f = managerFixture();

    $response = $this->actingAs($f['owner'])
        ->getJson(route('platform.organization.headquarters.manager.candidates', ['type' => 'shops', 'headquarter' => $f['a']->id]))
        ->assertOk()
        ->assertJsonPath('current.0.name', 'Kierownik A');

    $byName = collect($response->json('candidates'))->keyBy('name');

    expect($byName->keys()->all())->toEqualCanonicalizing(['Kierownik A', 'Kierownik B', 'Lider B1', 'Wolny pracownik'])
        ->and($byName['Kierownik A']['current'])->toBeTrue()
        ->and($byName['Kierownik B']['warning'])->toContain('Siedziba B')->toContain('bez kierownika')
        // the only leader of a branch is blocked: a branch keeps at least one manager
        ->and($byName['Lider B1']['blocked'])->toBeTrue()
        ->and($byName['Lider B1']['warning'])->toContain('Placówka B1')->toContain('co najmniej jednego kierownika')
        ->and($byName['Kierownik B']['blocked'])->toBeFalse()
        ->and($byName['Wolny pracownik']['warning'])->toContain('Zmieni rolę');
});

test('do siedziby można dodać kilku kierowników (praca zmianowa), poprzedni zostaje', function () {
    $f = managerFixture();
    $free = User::factory()->create(['role' => UserRole::SHOP_WORKER, 'organization_id' => $f['org']->id, 'name' => 'Drugi wolny']);
    $url = route('platform.organization.headquarters.manager.assign', ['type' => 'shops', 'headquarter' => $f['a']->id]);

    // the branch needs a leader after the current one leaves: the free worker is added there first
    $this->actingAs($f['managerB'])
        ->post(route('shop.owner.branches.leaders.assign', $f['branch']->id), ['user_id' => $f['worker']->id])
        ->assertSessionHasNoErrors();

    // a branch leader becomes an additional manager of the headquarters and leaves his branch
    $this->actingAs($f['owner'])->post($url, ['user_id' => $f['leader']->id])->assertSessionHasNoErrors();
    $this->post($url, ['user_id' => $free->id])->assertSessionHasNoErrors();

    $leader = $f['leader']->fresh();
    expect($leader->role)->toBe(UserRole::SHOP_OWNER)
        ->and($leader->shop_headquarter_id)->toBe($f['a']->id)
        ->and($leader->shop_branch_id)->toBeNull()
        ->and($free->fresh()->role)->toBe(UserRole::SHOP_OWNER)
        ->and($f['worker']->fresh()->role)->toBe(UserRole::SHOP_LEADER)
        ->and($f['managerA']->fresh()->shop_headquarter_id)->toBe($f['a']->id)
        ->and(User::where('shop_headquarter_id', $f['a']->id)->where('role', UserRole::SHOP_OWNER)->count())->toBe(3)
        // the other headquarters keeps its manager
        ->and($f['managerB']->fresh()->shop_headquarter_id)->toBe($f['b']->id);

    $this->getJson(route('platform.organization.headquarters.manager.candidates', ['type' => 'shops', 'headquarter' => $f['a']->id]))
        ->assertJsonCount(3, 'current');
});

test('kierownik innej siedziby jest przenoszony do tej, a tamta zostaje bez kierownika', function () {
    $f = managerFixture();

    $this->actingAs($f['owner'])
        ->post(route('platform.organization.headquarters.manager.assign', ['type' => 'shops', 'headquarter' => $f['a']->id]), ['user_id' => $f['managerB']->id])
        ->assertSessionHasNoErrors();

    expect($f['managerB']->fresh()->shop_headquarter_id)->toBe($f['a']->id)
        ->and(User::where('shop_headquarter_id', $f['b']->id)->where('role', UserRole::SHOP_OWNER)->exists())->toBeFalse();
});

test('kierownika można odłączyć od siedziby, a odłączony nie założy nowej siedziby', function () {
    $f = managerFixture();

    $this->actingAs($f['owner'])
        ->delete(route('platform.organization.headquarters.manager.remove', ['type' => 'shops', 'headquarter' => $f['a']->id, 'user' => $f['managerA']->id]))
        ->assertSessionHasNoErrors();

    $detached = $f['managerA']->fresh();
    expect($detached->shop_headquarter_id)->toBeNull()
        ->and($detached->organization_id)->toBe($f['org']->id);

    // he does not get the form for founding a new headquarters
    $this->actingAs($detached)->get(route('shop.owner.setup'))->assertForbidden();
    $this->post(route('shop.owner.setup.store'), ['name' => 'Nowa'])->assertForbidden();
    expect(ShopHeadquarter::where('name', 'Nowa')->exists())->toBeFalse();

    // removing someone who is not a manager of this headquarters is rejected
    $this->actingAs($f['owner'])
        ->delete(route('platform.organization.headquarters.manager.remove', ['type' => 'shops', 'headquarter' => $f['a']->id, 'user' => $f['managerB']->id]))
        ->assertSessionHasErrors('user_id');
    expect($f['managerB']->fresh()->shop_headquarter_id)->toBe($f['b']->id);
});

test('kierownik siedziby dodaje i odłącza kierowników swojej placówki', function () {
    $f = managerFixture();
    $url = route('shop.owner.branches.leaders.assign', $f['branch']->id);

    // managerB manages headquarters B, which owns the branch
    $this->actingAs($f['managerB'])
        ->getJson(route('shop.owner.branches.leaders.candidates', $f['branch']->id))
        ->assertOk()
        ->assertJsonPath('current.0.name', 'Lider B1');

    $this->post($url, ['user_id' => $f['worker']->id])->assertSessionHasNoErrors();

    $worker = $f['worker']->fresh();
    expect($worker->role)->toBe(UserRole::SHOP_LEADER)
        ->and($worker->shop_branch_id)->toBe($f['branch']->id)
        ->and($worker->shop_headquarter_id)->toBe($f['b']->id)
        // the first leader stays: shift work
        ->and($f['leader']->fresh()->shop_branch_id)->toBe($f['branch']->id);

    $this->delete(route('shop.owner.branches.leaders.remove', ['branch' => $f['branch']->id, 'user' => $f['leader']->id]))
        ->assertSessionHasNoErrors();
    expect($f['leader']->fresh()->shop_branch_id)->toBeNull()
        ->and($f['leader']->fresh()->shop_headquarter_id)->toBeNull();

    // a detached leader does not get the form for founding a branch either
    $this->actingAs($f['leader']->fresh())->get(route('shop.leader.setup'))->assertForbidden();
});

test('przypisanie kierownika do placówki ostrzega o skutkach', function () {
    $f = managerFixture();

    $byName = collect($this->actingAs($f['managerB'])
        ->getJson(route('shop.owner.branches.leaders.candidates', $f['branch']->id))
        ->json('candidates'))->keyBy('name');

    expect($byName['Lider B1']['current'])->toBeTrue()
        ->and($byName['Kierownik B']['warning'])->toContain('Siedziba B')->toContain('bez kierownika')
        ->and($byName['Wolny pracownik']['warning'])->toContain('kierownika placówki');
});

test('kierownika placówki zmienia tylko kierownik jej siedziby', function () {
    $f = managerFixture();
    $assign = route('shop.owner.branches.leaders.assign', $f['branch']->id);

    // the manager of ANOTHER headquarters of the same organization
    $this->actingAs($f['managerA'])->post($assign, ['user_id' => $f['worker']->id])->assertForbidden();
    // other roles (the organization owner has no such panel route either)
    foreach (['owner', 'leader', 'worker'] as $who) {
        $this->actingAs($f[$who])->post($assign, ['user_id' => $f['worker']->id])->assertForbidden();
    }
    // a foreign organization
    $this->actingAs($f['foreign'])->post($assign, ['user_id' => $f['worker']->id])->assertForbidden();
    // a missing branch
    $this->actingAs($f['managerB'])->post(route('shop.owner.branches.leaders.assign', 999999), ['user_id' => $f['worker']->id])->assertNotFound();

    expect($f['worker']->fresh()->role)->toBe(UserRole::SHOP_WORKER);
});

test('nie można przypisać osoby spoza organizacji, właściciela, osoby z hurtowni ani obecnego kierownika', function (string $who) {
    $f = managerFixture();
    $id = match ($who) {
        'obca organizacja' => $f['foreign']->id,
        'właściciel organizacji' => $f['owner']->id,
        'osoba z hurtowni' => $f['warehouseManager']->id,
        'obecny kierownik' => $f['managerA']->id,
        'nieistniejąca' => 999999,
    };

    $this->actingAs($f['owner'])
        ->post(route('platform.organization.headquarters.manager.assign', ['type' => 'shops', 'headquarter' => $f['a']->id]), ['user_id' => $id])
        ->assertSessionHasErrors('user_id');

    expect($f['managerA']->fresh()->shop_headquarter_id)->toBe($f['a']->id);
})->with(['obca organizacja', 'właściciel organizacji', 'osoba z hurtowni', 'obecny kierownik', 'nieistniejąca']);

test('zmiana kierownika jest zamknięta dla innych ról i cudzych siedzib', function () {
    $f = managerFixture();
    $candidates = fn ($hq) => route('platform.organization.headquarters.manager.candidates', ['type' => 'shops', 'headquarter' => $hq->id]);
    $assign = fn ($hq) => route('platform.organization.headquarters.manager.assign', ['type' => 'shops', 'headquarter' => $hq->id]);

    // roles other than the organization owner
    foreach (['managerA', 'leader', 'worker'] as $who) {
        $this->actingAs($f[$who])->getJson($candidates($f['a']))->assertForbidden();
        $this->post($assign($f['a']), ['user_id' => $f['worker']->id])->assertForbidden();
    }
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->getJson($candidates($f['a']))->assertForbidden();

    // the owner of another organization cannot touch this one's headquarters
    $this->actingAs($f['foreignOwner'])->getJson($candidates($f['a']))->assertForbidden();
    $this->post($assign($f['a']), ['user_id' => $f['foreign']->id])->assertForbidden();

    // ...and the owner cannot touch a foreign headquarters
    $this->actingAs($f['owner'])->getJson($candidates($f['foreignHq']))->assertForbidden();
    $this->actingAs($f['owner'])->getJson(route('platform.organization.headquarters.manager.candidates', ['type' => 'shops', 'headquarter' => 999999]))->assertNotFound();

    expect($f['worker']->fresh()->role)->toBe(UserRole::SHOP_WORKER);
});


test('obecni kierownicy siedziby i placówki są widoczni z telefonem i rolą', function () {
    $f = managerFixture();
    $f['managerA']->update(['phone' => '+48600100200']);

    $this->actingAs($f['owner'])->get(route('platform.organization.headquarters.index', 'shops'))
        ->assertInertia(fn ($page) => $page
            ->has('headquarters.0.owners.0', fn ($owner) => $owner
                ->where('name', 'Kierownik A')
                ->where('phone', '+48600100200')
                ->where('role', UserRole::SHOP_OWNER->label())
                ->etc()));

    $this->actingAs($f['managerB'])->get(route('shop.owner.branches.index'))
        ->assertInertia(fn ($page) => $page
            ->where('branches.0.managers.0.name', 'Lider B1')
            ->where('branches.0.managers.0.role', UserRole::SHOP_LEADER->label()));
});

test('kierownika siedziby można zmienić na pracownika, ale tylko w obrębie tej samej grupy', function () {
    $f = managerFixture();

    $this->actingAs($f['owner'])
        ->patch(route('platform.organization.headquarters.manager.demote', ['type' => 'shops', 'headquarter' => $f['a']->id, 'user' => $f['managerA']->id]), ['role' => 'worker'])
        ->assertSessionHasNoErrors();

    $manager = $f['managerA']->fresh();
    expect($manager->role)->toBe(UserRole::SHOP_WORKER)
        ->and($manager->shop_headquarter_id)->toBeNull()
        ->and($manager->warehouse_headquarter_id)->toBeNull();

    // a warehouse manager becomes a WAREHOUSE worker
    $this->patch(route('platform.organization.headquarters.manager.demote', ['type' => 'warehouses', 'headquarter' => $f['warehouse']->id, 'user' => $f['warehouseManager']->id]), ['role' => 'worker'])
        ->assertSessionHasNoErrors();
    expect($f['warehouseManager']->fresh()->role)->toBe(UserRole::WAREHOUSE_WORKER);

    // a shop manager cannot be changed through the warehouse endpoint (and vice versa): another group
    $this->patch(route('platform.organization.headquarters.manager.demote', ['type' => 'warehouses', 'headquarter' => $f['warehouse']->id, 'user' => $f['managerB']->id]), ['role' => 'worker'])
        ->assertSessionHasErrors('user_id');
    expect($f['managerB']->fresh()->role)->toBe(UserRole::SHOP_OWNER);

    // only the worker role is allowed, and only for someone who really manages this headquarters
    $this->patch(route('platform.organization.headquarters.manager.demote', ['type' => 'shops', 'headquarter' => $f['b']->id, 'user' => $f['managerB']->id]), ['role' => 'admin'])
        ->assertSessionHasErrors('role');
    $this->patch(route('platform.organization.headquarters.manager.demote', ['type' => 'shops', 'headquarter' => $f['a']->id, 'user' => $f['managerB']->id]), ['role' => 'worker'])
        ->assertSessionHasErrors('user_id');
    expect($f['managerB']->fresh()->role)->toBe(UserRole::SHOP_OWNER);
});

test('kierownika placówki można zmienić na pracownika tej samej placówki', function () {
    $f = managerFixture();

    // the only leader of the branch cannot be demoted
    $this->actingAs($f['managerB'])
        ->patch(route('shop.owner.branches.leaders.demote', ['branch' => $f['branch']->id, 'user' => $f['leader']->id]), ['role' => 'worker'])
        ->assertSessionHasErrors('user_id');
    expect($f['leader']->fresh()->role)->toBe(UserRole::SHOP_LEADER);

    // once there is a second one, the first can be
    $this->post(route('shop.owner.branches.leaders.assign', $f['branch']->id), ['user_id' => $f['worker']->id])->assertSessionHasNoErrors();
    $this->patch(route('shop.owner.branches.leaders.demote', ['branch' => $f['branch']->id, 'user' => $f['leader']->id]), ['role' => 'worker'])
        ->assertSessionHasNoErrors();

    $leader = $f['leader']->fresh();
    expect($leader->role)->toBe(UserRole::SHOP_WORKER)
        ->and($leader->shop_branch_id)->toBe($f['branch']->id)
        ->and($leader->shop_headquarter_id)->toBe($f['b']->id);

    // the manager of another headquarters cannot do it
    $this->actingAs($f['managerA'])
        ->patch(route('shop.owner.branches.leaders.demote', ['branch' => $f['branch']->id, 'user' => $f['leader']->id]), ['role' => 'worker'])
        ->assertForbidden();
});

test('placówka zawsze ma co najmniej jednego kierownika: jedynego nie można odwołać, zmienić ani przenieść', function () {
    $f = managerFixture();
    $branch = $f['branch'];
    $assignHq = route('platform.organization.headquarters.manager.assign', ['type' => 'shops', 'headquarter' => $f['a']->id]);
    $leaders = fn (string $name, array $params = []) => route("shop.owner.branches.leaders.$name", ['branch' => $branch->id, ...$params]);

    // dismiss and demote: refused (the manager of the headquarters acts)
    $this->actingAs($f['managerB']);
    $this->delete($leaders('remove', ['user' => $f['leader']->id]))->assertSessionHasErrors('user_id');
    $this->patch($leaders('demote', ['user' => $f['leader']->id]), ['role' => 'worker'])->assertSessionHasErrors('user_id');

    // move to a headquarters (the owner acts) and to another branch (the manager of that headquarters acts)
    $this->actingAs($f['owner'])->post($assignHq, ['user_id' => $f['leader']->id])->assertSessionHasErrors('user_id');
    $other = ShopBranch::create(['organization_id' => $f['org']->id, 'shop_headquarter_id' => $f['b']->id, 'name' => 'Placówka B2']);
    $this->actingAs($f['managerB'])->post(route('shop.owner.branches.leaders.assign', $other->id), ['user_id' => $f['leader']->id])
        ->assertSessionHasErrors('user_id');

    $leader = $f['leader']->fresh();
    expect($leader->role)->toBe(UserRole::SHOP_LEADER)->and($leader->shop_branch_id)->toBe($branch->id);

    // with a second leader the first one can be dismissed - and then the second one is the only one
    $this->post($leaders('assign'), ['user_id' => $f['worker']->id])->assertSessionHasNoErrors();
    $this->delete($leaders('remove', ['user' => $f['leader']->id]))->assertSessionHasNoErrors();
    expect($f['leader']->fresh()->shop_branch_id)->toBeNull();

    $this->delete($leaders('remove', ['user' => $f['worker']->id]))->assertSessionHasErrors('user_id');
    expect($f['worker']->fresh()->shop_branch_id)->toBe($branch->id);
});

test('siedziba nie ma takiego ograniczenia: jedynego kierownika siedziby można odwołać', function () {
    $f = managerFixture();

    $this->actingAs($f['owner'])
        ->delete(route('platform.organization.headquarters.manager.remove', ['type' => 'shops', 'headquarter' => $f['a']->id, 'user' => $f['managerA']->id]))
        ->assertSessionHasNoErrors();

    expect($f['managerA']->fresh()->shop_headquarter_id)->toBeNull();
});
