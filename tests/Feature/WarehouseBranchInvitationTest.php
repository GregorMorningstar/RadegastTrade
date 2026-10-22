<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Kierownik siedziby hurtowni w organizacji z NIP-em.
 */
function warehouseManager(?Organization $organization = null): User
{
    $organization ??= Organization::create(['name' => 'Org', 'NIP' => '5252344078']);
    $headquarter = WarehouseHeadquarter::create(['organization_id' => $organization->id, 'name' => 'Hurtownia główna']);

    return User::factory()->create([
        'role' => UserRole::WAREHOUSE_OWNER,
        'organization_id' => $organization->id,
        'warehouse_headquarter_id' => $headquarter->id,
    ]);
}

test('kierownik hurtowni zaprasza kierownika placówki, a ten zakłada placówkę z kluczami organizacji i siedziby', function () {
    Notification::fake();
    $manager = warehouseManager();

    $this->actingAs($manager)
        ->post(route('warehouse.owner.branches.invitations.store'), ['email' => 'Leader@Example.com', 'phone' => '+48'.fake()->unique()->numerify('#########')])
        ->assertRedirect(route('warehouse.owner.branches.index'));

    $invitation = OrganizationInvitation::first();
    expect($invitation->role)->toBe(UserRole::WAREHOUSE_LEADER)
        ->and($invitation->email)->toBe('leader@example.com')
        ->and($invitation->organization_id)->toBe($manager->organization_id)
        ->and($invitation->warehouse_headquarter_id)->toBe($manager->warehouse_headquarter_id)
        ->and($invitation->shop_headquarter_id)->toBeNull();

    $code = null;
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($n) use (&$code) {
        $code = $n->verificationCode;

        return true;
    });

    $leader = User::factory()->create(['email' => 'leader@example.com', 'role' => UserRole::Employee]);

    $this->actingAs($leader)
        ->post(route('organization-invitations.store', $invitation), ['code' => $code])
        ->assertRedirect(route('warehouse.leader.dashboard'));

    $this->get(route('warehouse.leader.dashboard'))->assertRedirect(route('warehouse.leader.setup'));
    $this->get(route('warehouse.leader.setup'))->assertOk();

    $this->post(route('warehouse.leader.setup.store'), ['name' => 'Magazyn 1', 'NIP' => '5252344078', 'organization_id' => 999])
        ->assertRedirect(route('warehouse.leader.dashboard'));

    $branch = WarehouseBranch::first();
    expect($branch->organization_id)->toBe($manager->organization_id)
        ->and($branch->warehouse_headquarter_id)->toBe($manager->warehouse_headquarter_id)
        ->and($branch->barcode)->not->toBeNull()
        ->and($leader->refresh()->warehouse_branch_id)->toBe($branch->id)
        ->and($invitation->refresh()->warehouse_branch_id)->toBe($branch->id);

    $this->get(route('warehouse.leader.dashboard'))->assertOk();
});

test('kierownik hurtowni widzi tylko swoje placówki, a cudzych zaproszeń nie anuluje', function () {
    $manager = warehouseManager();
    $foreign = warehouseManager(Organization::create(['name' => 'Obca']));
    [$foreignInvitation] = OrganizationInvitation::issue(
        $foreign->organization, 'a@example.com', $foreign, UserRole::WAREHOUSE_LEADER, $foreign->warehouseHeadquarter,
    );

    $this->actingAs($manager)
        ->delete(route('warehouse.owner.branches.invitations.destroy', $foreignInvitation->id))
        ->assertNotFound();

    $this->get(route('warehouse.owner.branches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/warehouses/owner/branches/index')
            ->where('group', 'warehouse')
            ->where('headquarter.id', $manager->warehouse_headquarter_id)
            ->has('newInvitations', 0));
});

test('administrator widzi placówki hurtowni', function () {
    $manager = warehouseManager();
    WarehouseBranch::create([
        'organization_id' => $manager->organization_id,
        'warehouse_headquarter_id' => $manager->warehouse_headquarter_id,
        'name' => 'Magazyn 1',
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get('/platform/admin/warehouses/branches')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/admin/warehouses/branches/index')
            ->has('rows', 1)
            ->where('rows.0.headquarter', 'Hurtownia główna'));
});

test('dashboard kierownika placówki pokazuje kody kreskowe placówki i siedziby', function () {
    $manager = warehouseManager();
    $branch = WarehouseBranch::create([
        'organization_id' => $manager->organization_id,
        'warehouse_headquarter_id' => $manager->warehouse_headquarter_id,
        'name' => 'Magazyn 1',
    ]);
    $leader = User::factory()->create([
        'role' => UserRole::WAREHOUSE_LEADER,
        'organization_id' => $manager->organization_id,
        'warehouse_headquarter_id' => $manager->warehouse_headquarter_id,
        'warehouse_branch_id' => $branch->id,
    ]);

    $this->actingAs($leader)
        ->get(route('warehouse.leader.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/warehouses/leader/dashboard/index')
            ->where('branch.name', 'Magazyn 1')
            ->where('branch.barcode', fn ($code) => preg_match('/^2202\\d{9}$/', $code) === 1)
            ->where('branch.headquarter.barcode', fn ($code) => preg_match('/^2201\\d{9}$/', $code) === 1));
});
