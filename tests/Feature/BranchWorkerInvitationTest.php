<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Kierownik placówki sklepu albo hurtowni w pełnym łańcuchu: organizacja → siedziba → placówka.
 *
 * @return array{leader: User, organization: Organization, headquarter: ShopHeadquarter|WarehouseHeadquarter, branch: ShopBranch|WarehouseBranch}
 */
function branchLeader(string $type = 'shop', ?Organization $organization = null): array
{
    $organization ??= Organization::create(['name' => 'Organizacja 1', 'NIP' => '5252344078']);

    if ($type === 'shop') {
        $headquarter = ShopHeadquarter::create(['organization_id' => $organization->id, 'name' => 'Sklep główny']);
        $branch = ShopBranch::create(['organization_id' => $organization->id, 'shop_headquarter_id' => $headquarter->id, 'name' => 'Placówka 1']);
        $columns = ['shop_headquarter_id' => $headquarter->id, 'shop_branch_id' => $branch->id];
        $role = UserRole::SHOP_LEADER;
    } else {
        $headquarter = WarehouseHeadquarter::create(['organization_id' => $organization->id, 'name' => 'Hurtownia główna']);
        $branch = WarehouseBranch::create(['organization_id' => $organization->id, 'warehouse_headquarter_id' => $headquarter->id, 'name' => 'Magazyn 1']);
        $columns = ['warehouse_headquarter_id' => $headquarter->id, 'warehouse_branch_id' => $branch->id];
        $role = UserRole::WAREHOUSE_LEADER;
    }

    $leader = User::factory()->create(['role' => $role, 'organization_id' => $organization->id, ...$columns]);

    return compact('leader', 'organization', 'headquarter', 'branch');
}

test('kierownik placówki zaprasza pracownika z kompletem kluczy: organizacja, siedziba, placówka', function (string $type, string $group, UserRole $workerRole, string $hqColumn, string $branchColumn) {
    Notification::fake();
    $f = branchLeader($type);

    $this->actingAs($f['leader'])
        ->post(route("$group.leader.workers.invitations.store"), ['email' => 'Pracownik@Example.com', 'phone' => '600 111 222'])
        ->assertRedirect(route("$group.leader.workers.index"));

    $invitation = OrganizationInvitation::first();
    expect($invitation->role)->toBe($workerRole)
        ->and($invitation->email)->toBe('pracownik@example.com')
        ->and($invitation->phone)->toBe('+48600111222')
        ->and($invitation->organization_id)->toBe($f['organization']->id)
        ->and($invitation->{$hqColumn})->toBe($f['headquarter']->id)
        ->and($invitation->{$branchColumn})->toBe($f['branch']->id);

    $code = null;
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($n) use (&$code) {
        $code = $n->verificationCode;

        return true;
    });

    $worker = User::factory()->create(['email' => 'pracownik@example.com', 'role' => UserRole::Employee]);

    $this->actingAs($worker)
        ->post(route('organization-invitations.store', $invitation), ['code' => $code])
        ->assertRedirect(route("$group.worker.dashboard"));

    $worker->refresh();
    expect($worker->role)->toBe($workerRole)
        ->and($worker->organization_id)->toBe($f['organization']->id)
        ->and($worker->{$hqColumn})->toBe($f['headquarter']->id)
        ->and($worker->{$branchColumn})->toBe($f['branch']->id)
        ->and($worker->phone)->toBe('+48600111222');

    $this->actingAs($f['leader'])
        ->get(route("$group.leader.workers.index"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component(($group === "shop" ? "platform/shop" : "platform/warehouses")."/leader/workers/index")
            ->where('branch.id', $f['branch']->id)
            ->has('workers', 1)
            ->where('workers.0.email', 'pracownik@example.com')
            ->has('invitations', 0));
})->with([
    'sklep' => ['shop', 'shop', UserRole::SHOP_WORKER, 'shop_headquarter_id', 'shop_branch_id'],
    'hurtownia' => ['warehouse', 'warehouse', UserRole::WAREHOUSE_WORKER, 'warehouse_headquarter_id', 'warehouse_branch_id'],
]);

test('zaproszenie pracownika wymaga poprawnego telefonu i nie można zaprosić siebie', function () {
    Notification::fake();
    $f = branchLeader();
    $url = route('shop.leader.workers.invitations.store');

    $this->actingAs($f['leader'])->post($url, ['email' => 'a@example.com'])->assertSessionHasErrors('phone');
    $this->post($url, ['email' => 'a@example.com', 'phone' => '123'])->assertSessionHasErrors('phone');
    $this->post($url, ['email' => 'zły', 'phone' => '600111222'])->assertSessionHasErrors('email');
    $this->post($url, ['email' => $f['leader']->email, 'phone' => '600111222'])->assertSessionHasErrors('email');

    expect(OrganizationInvitation::count())->toBe(0);
});

test('kierownik nie anuluje zaproszeń z innej placówki ani organizacji, a zaproszenia nowej placówki nie są pracownicze', function () {
    $f = branchLeader();
    $sibling = ShopBranch::create(['organization_id' => $f['organization']->id, 'shop_headquarter_id' => $f['headquarter']->id, 'name' => 'Placówka 2']);
    [$siblingInvitation] = OrganizationInvitation::issue(
        $f['organization'], 'a@example.com', $f['leader'], UserRole::SHOP_WORKER, null, null, $sibling,
    );
    $foreign = branchLeader('shop', Organization::create(['name' => 'Obca']));
    [$foreignInvitation] = OrganizationInvitation::issue(
        $foreign['organization'], 'b@example.com', $foreign['leader'], UserRole::SHOP_WORKER, null, null, $foreign['branch'],
    );
    [$leaderInvitation] = OrganizationInvitation::issue(
        $f['organization'], 'c@example.com', $f['leader'], UserRole::SHOP_LEADER, $f['headquarter'],
    );

    $this->actingAs($f['leader']);

    foreach ([$siblingInvitation, $foreignInvitation, $leaderInvitation] as $invitation) {
        $this->delete(route('shop.leader.workers.invitations.destroy', $invitation->id))->assertForbidden();
        expect($invitation->fresh()->is_active)->toBeTrue();
    }
});

test('pracownik nie może zapraszać, a kierownik bez placówki nie ma dostępu do pracowników', function () {
    $f = branchLeader();
    $worker = User::factory()->create(['role' => UserRole::SHOP_WORKER, 'organization_id' => $f['organization']->id]);

    $this->actingAs($worker)->post(route('shop.leader.workers.invitations.store'), ['email' => 'a@example.com', 'phone' => '600111222'])
        ->assertForbidden();

    $this->actingAs(User::factory()->create(['role' => UserRole::SHOP_LEADER]))
        ->get(route('shop.leader.workers.index'))
        ->assertForbidden();
});

test('konto z innej organizacji nie może zostać zaproszone jako pracownik placówki', function () {
    Notification::fake();
    $f = branchLeader();
    $other = branchLeader('shop', Organization::create(['name' => 'Obca']));

    $this->actingAs($f['leader'])
        ->post(route('shop.leader.workers.invitations.store'), ['email' => 'inny@example.com', 'phone' => '600111222']);
    $invitation = OrganizationInvitation::first();

    // konto należące do innej organizacji jest odrzucane już przy zapraszaniu
    User::factory()->create(['email' => 'zajety@example.com', 'role' => UserRole::SHOP_WORKER, 'organization_id' => $other['organization']->id]);
    $this->post(route('shop.leader.workers.invitations.store'), ['email' => 'zajety@example.com', 'phone' => '600333444'])
        ->assertSessionHasErrors('email');
    expect(OrganizationInvitation::count())->toBe(1)->and($invitation->is_active)->toBeTrue();
});

test('zespół organizacji i siedziby zawiera pracowników placówek z rolami', function () {
    $f = branchLeader();
    $owner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $f['organization']->id]);
    User::factory()->create([
        'role' => UserRole::SHOP_WORKER, 'organization_id' => $f['organization']->id,
        'shop_headquarter_id' => $f['headquarter']->id, 'shop_branch_id' => $f['branch']->id, 'name' => 'Pracownik Jan',
    ]);
    OrganizationInvitation::issue($f['organization'], 'nowy@example.com', $f['leader'], UserRole::SHOP_WORKER, null, null, $f['branch']);

    $this->actingAs($owner)->get(route('platform.organization.team'))
        ->assertInertia(fn ($page) => $page->where('members', fn ($members) => collect($members)->contains(
            fn ($m) => $m['name'] === 'Pracownik Jan' && $m['roleValue'] === 'shop_worker' && $m['unit'] === 'Placówka sklepu: Placówka 1',
        ) && collect($members)->contains(
            fn ($m) => $m['email'] === 'nowy@example.com' && $m['status'] === 'invited' && $m['unit'] === 'Placówka sklepu: Placówka 1',
        )));
});

test('formularz uzupełnienia danych placówki dostaje telefon z zaproszenia', function () {
    $f = branchLeader();
    $manager = $f['leader'];

    // kierownik zaproszony do nowej placówki, jeszcze bez placówki, z telefonem z zaproszenia
    $leader = User::factory()->create([
        'role' => UserRole::SHOP_LEADER,
        'organization_id' => $f['organization']->id,
        'shop_headquarter_id' => $f['headquarter']->id,
        'phone' => '+48600111222',
    ]);

    $this->actingAs($leader)
        ->get(route('shop.leader.setup'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/shop/leader/setup')
            ->where('phone', '+48600111222')
            ->where('email', $leader->email));
});

test('pracownik widzi zespół swojej placówki: kierownika i współpracowników, bez zaproszeń i obcych', function (string $type, string $group, UserRole $workerRole, string $hq, string $br) {
    $f = branchLeader($type);
    $columns = ['organization_id' => $f['organization']->id, $hq => $f['headquarter']->id, $br => $f['branch']->id];
    $worker = User::factory()->create(['role' => $workerRole, 'name' => 'Pracownik A', ...$columns]);
    User::factory()->create(['role' => $workerRole, 'name' => 'Pracownik B', ...$columns]);
    OrganizationInvitation::issue($f['organization'], 'oczekuje@example.com', $f['leader'], $workerRole, null, null, $f['branch']);

    $other = branchLeader($type, Organization::create(['name' => 'Obca']));
    User::factory()->create(['role' => $workerRole, 'name' => 'Obcy pracownik', 'organization_id' => $other['organization']->id, $hq => $other['headquarter']->id, $br => $other['branch']->id]);

    $this->actingAs($worker)
        ->get(route("$group.worker.team"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component(($group === "shop" ? "platform/shop" : "platform/warehouses")."/worker/team/index")
            ->where('scope', 'branch')
            ->where('members', fn ($members) => collect($members)->pluck('name')->sort()->values()->all() === collect(['Pracownik A', 'Pracownik B', $f['leader']->name])->sort()->values()->all()
                && collect($members)->first()['roleValue'] === $f['leader']->role->value
                && collect($members)->every(fn ($m) => $m['status'] === 'active')));

    // kierownik widzi te same osoby oraz zaproszenia pracowników
    $this->actingAs($f['leader'])
        ->get(route("$group.leader.team"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component(($group === "shop" ? "platform/shop" : "platform/warehouses")."/leader/team/index")
            ->where('members', fn ($members) => collect($members)->contains(fn ($m) => $m['email'] === 'oczekuje@example.com' && $m['status'] === 'invited')
                && collect($members)->pluck('name')->doesntContain('Obcy pracownik')));

    // dashboard pracownika z kodami placówki i siedziby
    $this->actingAs($worker)
        ->get(route("$group.worker.dashboard"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component(($group === "shop" ? "platform/shop" : "platform/warehouses")."/worker/dashboard/index")
            ->where('branch.id', $f['branch']->id)
            ->where('branch.barcode', $f['branch']->barcode)
            ->where('branch.headquarter.barcode', $f['headquarter']->barcode));
})->with([
    'sklep' => ['shop', 'shop', UserRole::SHOP_WORKER, 'shop_headquarter_id', 'shop_branch_id'],
    'hurtownia' => ['warehouse', 'warehouse', UserRole::WAREHOUSE_WORKER, 'warehouse_headquarter_id', 'warehouse_branch_id'],
]);

test('widok zespołu placówki jest zamknięty dla pracownika bez placówki', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SHOP_WORKER]))
        ->get(route('shop.worker.team'))
        ->assertForbidden();
});
