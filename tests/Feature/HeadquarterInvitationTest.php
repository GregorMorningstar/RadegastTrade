<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Notification;

const ORG_NIP = '5252344078';
const OTHER_NIP = '1234563218';

function organizationOwner(?Organization $organization = null): User
{
    $organization ??= Organization::create(['name' => 'Org', 'NIP' => ORG_NIP]);

    return User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $organization->id]);
}

/**
 * Kierownik zaproszony do założenia nowej siedziby, po przyjęciu zaproszenia (bez siedziby).
 */
function invitedManager(User $owner, string $type = 'shops'): User
{
    Notification::fake();

    test()->actingAs($owner)
        ->post(route('platform.organization.headquarters.invitations.store-new', $type), ['email' => 'kier@example.com', 'phone' => '+48'.fake()->unique()->numerify('#########')])
        ->assertRedirect(route('platform.organization.headquarters.index', $type));

    $invitation = OrganizationInvitation::latest('id')->first();
    $code = null;
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($n) use (&$code) {
        $code = $n->verificationCode;

        return true;
    });

    $user = User::factory()->create(['email' => 'kier@example.com', 'role' => UserRole::Employee]);

    test()->actingAs($user)->post(route('organization-invitations.store', $invitation), ['code' => $code]);

    return $user->refresh();
}

test('zaproszenie nowej siedziby niesie id organizacji, a kierownik po przyjęciu trafia do formularza siedziby', function () {
    $owner = organizationOwner();
    $manager = invitedManager($owner);

    $invitation = OrganizationInvitation::first();
    expect($invitation->organization_id)->toBe($owner->organization_id)
        ->and($invitation->role)->toBe(UserRole::SHOP_OWNER)
        ->and($invitation->shop_headquarter_id)->toBeNull()
        ->and($manager->role)->toBe(UserRole::SHOP_OWNER)
        ->and($manager->organization_id)->toBe($owner->organization_id);

    $this->actingAs($manager)->get(route('shop.owner.dashboard'))->assertRedirect(route('shop.owner.setup'));
    $this->get(route('shop.owner.setup'))->assertOk();
});

test('kierownik zakłada siedzibę z organization_id z zaproszenia, a id z formularza jest ignorowane', function () {
    $other = Organization::create(['name' => 'Inna']);
    $owner = organizationOwner();
    $manager = invitedManager($owner);

    $this->actingAs($manager)
        ->post(route('shop.owner.setup.store'), ['name' => 'Sklep główny', 'NIP' => ORG_NIP, 'organization_id' => $other->id])
        ->assertRedirect(route('shop.owner.dashboard'));

    $shop = ShopHeadquarter::first();
    expect($shop->organization_id)->toBe($owner->organization_id)
        ->and($shop->NIP)->toBe(ORG_NIP)
        ->and($manager->refresh()->shop_headquarter_id)->toBe($shop->id)
        ->and(OrganizationInvitation::first()->shop_headquarter_id)->toBe($shop->id);

    $this->get(route('shop.owner.dashboard'))->assertOk();
    $this->get(route('shop.owner.setup'))->assertRedirect(route('shop.owner.dashboard'));
    $this->post(route('shop.owner.setup.store'), ['name' => 'Drugi'])->assertForbidden();
});

test('siedziba może mieć inny NIP, ale istniejącego już NIP-u nie można użyć ponownie', function () {
    $owner = organizationOwner();
    $manager = invitedManager($owner, 'warehouses');

    $this->actingAs($manager)
        ->post(route('warehouse.owner.setup.store'), ['name' => 'Hurtownia A', 'NIP' => OTHER_NIP])
        ->assertSessionHasNoErrors();
    expect(WarehouseHeadquarter::first()->NIP)->toBe(OTHER_NIP);

    // druga organizacja próbuje użyć tego samego, już istniejącego NIP-u
    $secondOwner = organizationOwner(Organization::create(['name' => 'Druga', 'NIP' => '5260001246']));
    $secondManager = User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $secondOwner->organization_id]);
    // the manager founds the headquarters only with an accepted invitation
    [$secondInvitation] = OrganizationInvitation::issue($secondOwner->organization, $secondManager->email, $secondOwner, UserRole::SHOP_OWNER, null, '+48511222333');
    $secondInvitation->markAccepted();

    $this->actingAs($secondManager)
        ->post(route('shop.owner.setup.store'), ['name' => 'Sklep', 'NIP' => OTHER_NIP])
        ->assertSessionHasErrors('NIP');
    expect(ShopHeadquarter::count())->toBe(0);
});

test('nie można założyć siedziby z NIP-em innej organizacji ani o powtórzonej nazwie', function () {
    Organization::create(['name' => 'Inna', 'NIP' => OTHER_NIP]);
    $owner = organizationOwner();
    $manager = invitedManager($owner);

    $this->actingAs($manager)
        ->post(route('shop.owner.setup.store'), ['name' => 'Sklep', 'NIP' => OTHER_NIP])
        ->assertSessionHasErrors('NIP');

    ShopHeadquarter::create(['organization_id' => $owner->organization_id, 'name' => 'Sklep']);
    $this->post(route('shop.owner.setup.store'), ['name' => 'Sklep'])->assertSessionHasErrors('name');
});

test('właściciel organizacji nie może założyć siedziby przez formularz kierownika', function () {
    $this->actingAs(organizationOwner())->get('/shop/owner/setup')->assertForbidden();
});

test('właściciel zaprasza kierownika siedziby, a w bazie zostaje zaproszenie z rolą i siedzibą', function () {
    Notification::fake();
    $owner = organizationOwner();
    $shop = ShopHeadquarter::create(['organization_id' => $owner->organization_id, 'name' => 'Sklep']);

    $this->actingAs($owner)
        ->post(route('platform.organization.headquarters.invitations.store', ['shops', $shop->id]), ['email' => 'Kier@Example.com', 'phone' => '+48'.fake()->unique()->numerify('#########')])
        ->assertRedirect(route('platform.organization.headquarters.index', 'shops'));

    $invitation = OrganizationInvitation::first();

    expect($invitation->email)->toBe('kier@example.com')
        ->and($invitation->role)->toBe(UserRole::SHOP_OWNER)
        ->and($invitation->organization_id)->toBe($owner->organization_id)
        ->and($invitation->shop_headquarter_id)->toBe($shop->id);

    Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
});

test('nie można zaprosić do siedziby innej organizacji', function () {
    Notification::fake();
    $foreign = Organization::create(['name' => 'Obca']);
    $foreignShop = ShopHeadquarter::create(['organization_id' => $foreign->id, 'name' => 'Obcy sklep']);

    $this->actingAs(organizationOwner())
        ->post(route('platform.organization.headquarters.invitations.store', ['shops', $foreignShop->id]), ['email' => 'a@example.com', 'phone' => '+48'.fake()->unique()->numerify('#########')])
        ->assertForbidden();

    expect(OrganizationInvitation::count())->toBe(0);
    Notification::assertNothingSent();
});

test('nie można anulować zaproszenia innej organizacji', function () {
    $foreign = Organization::create(['name' => 'Obca']);
    $foreignShop = ShopHeadquarter::create(['organization_id' => $foreign->id, 'name' => 'Obcy sklep']);
    [$invitation] = OrganizationInvitation::issue(
        $foreign, 'a@example.com', User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $foreign->id]),
        UserRole::SHOP_OWNER, $foreignShop,
    );

    $this->actingAs(organizationOwner())
        ->delete(route('platform.organization.invitations.destroy', $invitation->id))
        ->assertForbidden();

    expect($invitation->fresh()->is_active)->toBeTrue();
});

test('zaproszony kierownik po przyjęciu dostaje rolę, organizację i siedzibę', function () {
    Notification::fake();
    $owner = organizationOwner();
    $warehouse = WarehouseHeadquarter::create(['organization_id' => $owner->organization_id, 'name' => 'Hurtownia']);

    $this->actingAs($owner)
        ->post(route('platform.organization.headquarters.invitations.store', ['warehouses', $warehouse->id]), ['email' => 'kier@example.com', 'phone' => '+48'.fake()->unique()->numerify('#########')]);

    $invitation = OrganizationInvitation::first();
    $code = null;
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($n) use (&$code) {
        $code = $n->verificationCode;

        return true;
    });

    $user = User::factory()->create(['email' => 'kier@example.com', 'role' => UserRole::Employee]);

    $this->actingAs($user)
        ->post(route('organization-invitations.store', $invitation), ['code' => $code])
        ->assertRedirect(route('warehouse.owner.dashboard'));

    $user->refresh();
    expect($user->role)->toBe(UserRole::WAREHOUSE_OWNER)
        ->and($user->organization_id)->toBe($owner->organization_id)
        ->and($user->warehouse_headquarter_id)->toBe($warehouse->id);
});

test('inny rodzaj konta niż właściciel organizacji nie ma dostępu do siedzib', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Manager]))
        ->get('/platform/organization/shops')
        ->assertForbidden();
});

test('dashboard kierownika pokazuje jego siedzibę i osoby z siedziby', function () {
    $owner = organizationOwner();
    $manager = invitedManager($owner);

    $this->actingAs($manager)
        ->post(route('shop.owner.setup.store'), ['name' => 'Sklep główny', 'NIP' => ORG_NIP]);

    $this->get(route('shop.owner.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/shop/owner/dashboard/index')
            ->where('headquarter.name', 'Sklep główny')
            ->where('headquarter.NIP', ORG_NIP)
            ->where('headquarter.organization', 'Org')
            ->where('stats.people', 1)
            ->where('people.0.email', 'kier@example.com'));
});
