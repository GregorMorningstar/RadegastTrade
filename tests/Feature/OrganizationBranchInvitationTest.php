<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Notification;

/**
 * An organization with its owner, a shop headquarters (with its manager) and a warehouse headquarters,
 * and a second organization with a headquarters of its own.
 *
 * @return array<string, mixed>
 */
function branchInviteFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);

    $shop = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep główny']);
    $warehouse = WarehouseHeadquarter::create(['organization_id' => $org->id, 'name' => 'Hurtownia główna']);

    return [
        'org' => $org,
        'owner' => User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $org->id]),
        'shop' => $shop,
        'warehouse' => $warehouse,
        'manager' => User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $org->id, 'shop_headquarter_id' => $shop->id]),
        'foreignOwner' => User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $other->id]),
        'foreignHq' => ShopHeadquarter::create(['organization_id' => $other->id, 'name' => 'Obca siedziba']),
    ];
}

test('właściciel organizacji zaprasza kierownika placówki sklepu w wybranej głównej siedzibie', function () {
    Notification::fake();
    $f = branchInviteFixture();

    $this->actingAs($f['owner'])
        ->post(route('platform.organization.branches.invitations.store', ['type' => 'shops', 'headquarter' => $f['shop']->id]), ['email' => 'Lider@Example.com', 'phone' => '600 100 200'])
        ->assertSessionHasNoErrors();

    $invitation = OrganizationInvitation::firstOrFail();
    expect($invitation->role)->toBe(UserRole::SHOP_LEADER)
        ->and($invitation->email)->toBe('lider@example.com')
        ->and($invitation->phone)->toBe('+48600100200')
        // the foreign keys: organization -> headquarters; the branch is created after accepting
        ->and($invitation->organization_id)->toBe($f['org']->id)
        ->and($invitation->shop_headquarter_id)->toBe($f['shop']->id)
        ->and($invitation->shop_branch_id)->toBeNull()
        ->and($invitation->invited_by)->toBe($f['owner']->id);

    Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
});

test('w hurtowni zaproszenie dostaje rolę kierownika placówki hurtowni, a nowe zaproszenie wycofuje poprzednie', function () {
    Notification::fake();
    $f = branchInviteFixture();
    $url = route('platform.organization.branches.invitations.store', ['type' => 'warehouses', 'headquarter' => $f['warehouse']->id]);

    $this->actingAs($f['owner'])->post($url, ['email' => 'mag@example.com', 'phone' => '600 100 201'])->assertSessionHasNoErrors();
    $this->post($url, ['email' => 'mag@example.com', 'phone' => '600 100 201'])->assertSessionHasNoErrors();

    $invitations = OrganizationInvitation::orderBy('id')->get();
    expect($invitations)->toHaveCount(2)
        ->and($invitations[0]->is_active)->toBeFalse()
        ->and($invitations[1]->is_active)->toBeTrue()
        ->and($invitations[1]->role)->toBe(UserRole::WAREHOUSE_LEADER)
        ->and($invitations[1]->warehouse_headquarter_id)->toBe($f['warehouse']->id);
});

test('zaproszenie do placówki wymaga e-maila i telefonu, a siedziba musi należeć do organizacji właściciela', function () {
    Notification::fake();
    $f = branchInviteFixture();
    $url = fn ($hq, $type = 'shops') => route('platform.organization.branches.invitations.store', ['type' => $type, 'headquarter' => $hq]);

    $this->actingAs($f['owner'])->post($url($f['shop']->id), [])->assertSessionHasErrors(['email', 'phone']);
    $this->post($url($f['shop']->id), ['email' => 'nie-email', 'phone' => '600100200'])->assertSessionHasErrors('email');
    $this->post($url($f['shop']->id), ['email' => 'a@example.com', 'phone' => '12'])->assertSessionHasErrors('phone');

    // a foreign headquarters and a missing one
    $this->post($url($f['foreignHq']->id), ['email' => 'a@example.com', 'phone' => '600100200'])->assertNotFound();
    $this->post($url(999999), ['email' => 'a@example.com', 'phone' => '600100200'])->assertNotFound();

    expect(OrganizationInvitation::count())->toBe(0);
    Notification::assertNothingSent();
});

test('zaproszenie do placówki wysyłają tylko właściciel organizacji, nikt inny przez ten adres', function () {
    Notification::fake();
    $f = branchInviteFixture();
    $url = route('platform.organization.branches.invitations.store', ['type' => 'shops', 'headquarter' => $f['shop']->id]);

    $this->actingAs($f['manager'])->post($url, ['email' => 'a@example.com', 'phone' => '600100200'])->assertForbidden();
    // the owner of another organization does not even see that the headquarters exists
    $this->actingAs($f['foreignOwner'])->post($url, ['email' => 'a@example.com', 'phone' => '600100200'])->assertNotFound();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->post($url, ['email' => 'a@example.com', 'phone' => '600100200'])->assertForbidden();

    expect(OrganizationInvitation::count())->toBe(0);
});

test('strona placówek pokazuje siedziby i oczekujące zaproszenia do placówek, które można anulować', function () {
    Notification::fake();
    $f = branchInviteFixture();

    $this->actingAs($f['owner'])
        ->post(route('platform.organization.branches.invitations.store', ['type' => 'shops', 'headquarter' => $f['shop']->id]), ['email' => 'lider@example.com', 'phone' => '600 100 200']);
    // an invitation of a headquarters manager is not a branch invitation
    $this->post(route('platform.organization.headquarters.invitations.store-new', 'shops'), ['email' => 'siedziba@example.com', 'phone' => '600 100 202']);

    $this->get(route('platform.organization.branches.index', 'shops'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/organization/shops/branches/index')
            ->where('type', 'shops')
            ->where('headquarters.0.name', 'Sklep główny')
            ->has('headquarters', 1)
            ->has('newInvitations', 1)
            ->where('newInvitations.0.email', 'lider@example.com')
            ->where('newInvitations.0.headquarter', 'Sklep główny'));

    $this->get(route('platform.organization.branches.index', 'warehouses'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('platform/organization/warehouses/branches/index')->has('headquarters', 1)->has('newInvitations', 0));

    // the owner cancels the branch invitation (the same endpoint as for the headquarters invitations)
    $branchInvitation = OrganizationInvitation::where('role', UserRole::SHOP_LEADER->value)->firstOrFail();
    $this->delete(route('platform.organization.invitations.destroy', $branchInvitation))->assertSessionHasNoErrors();
    expect($branchInvitation->fresh()->is_active)->toBeFalse();

    // someone else's invitation cannot be cancelled
    [$foreign] = OrganizationInvitation::issue($f['foreignHq']->organization, 'x@example.com', $f['foreignOwner'], UserRole::SHOP_LEADER, $f['foreignHq'], '+48600100209');
    $this->delete(route('platform.organization.invitations.destroy', $foreign))->assertNotFound();
});
