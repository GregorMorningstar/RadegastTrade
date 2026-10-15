<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;
use App\Notifications\OrganizationInvitationNotification;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Notification;

/**
 * Organizacja z właścicielem, siedzibą sklepu i hurtowni oraz ich kierownikami.
 *
 * @return array{org: Organization, owner: User, shop: ShopHeadquarter, shopManager: User, warehouse: WarehouseHeadquarter, warehouseManager: User}
 */
function teamFixture(): array
{
    $org = Organization::create(['name' => 'Org', 'NIP' => '5252344078']);
    $owner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $org->id, 'name' => 'Organizator']);
    $shop = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep']);
    $warehouse = WarehouseHeadquarter::create(['organization_id' => $org->id, 'name' => 'Hurtownia']);

    return [
        'org' => $org,
        'owner' => $owner,
        'shop' => $shop,
        'shopManager' => User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $org->id, 'shop_headquarter_id' => $shop->id, 'name' => 'Kierownik sklepu', 'phone' => '+48600100200']),
        'warehouse' => $warehouse,
        'warehouseManager' => User::factory()->create(['role' => UserRole::WAREHOUSE_OWNER, 'organization_id' => $org->id, 'warehouse_headquarter_id' => $warehouse->id, 'name' => 'Kierownik hurtowni']),
    ];
}

test('właściciel organizacji widzi cały zespół organizacji z rolami i zaproszeniami', function () {
    $f = teamFixture();
    OrganizationInvitation::issue($f['org'], 'nowy@example.com', $f['owner'], UserRole::SHOP_OWNER, null, '+48500500500');
    OrganizationInvitation::issue($f['org'], 'drugi@example.com', User::factory()->create(['role' => UserRole::Admin]), UserRole::ORGANIZATION_OWNER);
    $other = Organization::create(['name' => 'Inna']);
    User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $other->id, 'name' => 'Obcy']);

    $this->actingAs($f['owner'])
        ->get(route('platform.organization.team'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/organization/team/index')
            ->where('scope', 'organization')
            ->has('members', 5)
            ->where('members.0.roleValue', 'organization_owner')
            ->where('members', fn ($members) => collect($members)->pluck('name')->doesntContain('Obcy')
                && collect($members)->contains(fn ($m) => $m['email'] === 'nowy@example.com' && $m['status'] === 'invited' && $m['phone'] === '+48500500500' && $m['unit'] === 'Nowa siedziba sklepu')
                && collect($members)->contains(fn ($m) => $m['name'] === 'Kierownik sklepu' && $m['unit'] === 'Siedziba sklepu: Sklep' && $m['phone'] === '+48600100200')));
});

test('kierownik siedziby widzi organizatora, swoją siedzibę i zaproszone placówki, ale nie cudzą siedzibę', function () {
    $f = teamFixture();
    OrganizationInvitation::issue($f['org'], 'placowka@example.com', $f['shopManager'], UserRole::SHOP_LEADER, $f['shop']);
    OrganizationInvitation::issue($f['org'], 'inna@example.com', $f['warehouseManager'], UserRole::WAREHOUSE_LEADER, $f['warehouse']);

    $this->actingAs($f['shopManager'])
        ->get(route('shop.owner.team'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/shop/owner/team/index')
            ->where('scope', 'headquarter')
            ->where('members', fn ($members) => collect($members)->pluck('email')->all() === [
                $f['owner']->email, $f['shopManager']->email, 'placowka@example.com',
            ]));

    // hurtownia: te same zasady, własny zespół z organizatorem i zaproszoną placówką
    $this->actingAs($f['warehouseManager'])
        ->get(route('warehouse.owner.team'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/warehouses/owner/team/index')
            ->where('members', fn ($members) => collect($members)->pluck('email')->contains('inna@example.com')
                && collect($members)->pluck('email')->doesntContain('placowka@example.com')));
});

test('widok zespołu jest zamknięty dla innych ról', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Manager]))
        ->get('/platform/organization/team')->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => UserRole::SHOP_LEADER]))
        ->get('/shop/owner/team')->assertForbidden();
});

test('numery telefonów są normalizowane do postaci międzynarodowej', function (?string $input, ?string $expected) {
    expect(PhoneNumber::normalize($input))->toBe($expected);
})->with([
    ['600 100 200', '+48600100200'],
    ['600-100-200', '+48600100200'],
    ['+48 600 100 200', '+48600100200'],
    ['0048600100200', '+48600100200'],
    ['+44 20 7946 0958', '+442079460958'],
    ['', null],
    [null, null],
]);

test('zaproszenie kierownika wymaga poprawnego telefonu i e-maila', function (array $payload, string $field) {
    Notification::fake();
    $f = teamFixture();

    $this->actingAs($f['owner'])
        ->post(route('platform.organization.headquarters.invitations.store-new', 'shops'), $payload)
        ->assertSessionHasErrors($field);

    expect(OrganizationInvitation::count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'brak telefonu' => [['email' => 'a@example.com'], 'phone'],
    'za krótki telefon' => [['email' => 'a@example.com', 'phone' => '12345'], 'phone'],
    'litery w telefonie' => [['email' => 'a@example.com', 'phone' => 'abc123456'], 'phone'],
    'błędny e-mail' => [['email' => 'nie-email', 'phone' => '600100200'], 'email'],
    'brak e-maila' => [['phone' => '600100200'], 'email'],
]);

test('numer telefonu nie może należeć do innego konta ani do zaproszenia na inny adres', function () {
    Notification::fake();
    $f = teamFixture();
    $url = route('platform.organization.headquarters.invitations.store-new', 'shops');

    // numer innego konta (kierownik sklepu ma +48600100200), zapis z odstępami też jest rozpoznawany
    $this->actingAs($f['owner'])->post($url, ['email' => 'a@example.com', 'phone' => '600 100 200'])
        ->assertSessionHasErrors('phone');

    // numer czeka w zaproszeniu na inny adres
    OrganizationInvitation::issue($f['org'], 'b@example.com', $f['owner'], UserRole::SHOP_OWNER, null, '+48700700700');
    $this->post($url, ['email' => 'a@example.com', 'phone' => '700-700-700'])->assertSessionHasErrors('phone');

    // ten sam adres może dostać nowe zaproszenie z tym samym numerem (poprzednie zostaje wycofane)
    $this->post($url, ['email' => 'b@example.com', 'phone' => '700700700'])->assertSessionHasNoErrors();
    expect(OrganizationInvitation::where('email', 'b@example.com')->where('is_active', true)->count())->toBe(1)
        ->and(OrganizationInvitation::where('email', 'b@example.com')->where('is_active', true)->first()->phone)->toBe('+48700700700');
});

test('nie można zaprosić samego siebie', function () {
    Notification::fake();
    $f = teamFixture();

    $this->actingAs($f['owner'])
        ->post(route('platform.organization.headquarters.invitations.store-new', 'warehouses'), ['email' => strtoupper($f['owner']->email), 'phone' => '501501501'])
        ->assertSessionHasErrors('email');

    $this->actingAs($f['shopManager'])
        ->post(route('shop.owner.branches.invitations.store'), ['email' => $f['shopManager']->email, 'phone' => '501501501'])
        ->assertSessionHasErrors('email');

    expect(OrganizationInvitation::count())->toBe(0);
});

test('telefon z zaproszenia trafia do konta po przyjęciu zaproszenia', function () {
    Notification::fake();
    $f = teamFixture();

    $this->actingAs($f['owner'])
        ->post(route('platform.organization.headquarters.invitations.store-new', 'shops'), ['email' => 'nowy@example.com', 'phone' => '+48 501 502 503']);

    $invitation = OrganizationInvitation::first();
    $code = null;
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($n) use (&$code) {
        $code = $n->verificationCode;

        return true;
    });

    $user = User::factory()->create(['email' => 'nowy@example.com', 'role' => UserRole::Employee]);
    $this->actingAs($user)->post(route('organization-invitations.store', $invitation), ['code' => $code]);

    expect($user->refresh()->phone)->toBe('+48501502503')
        ->and($user->role)->toBe(UserRole::SHOP_OWNER);
});

test('rejestracja z zaproszenia zapisuje telefon z formularza (podpowiedziany z zaproszenia)', function () {
    $f = teamFixture();
    [$invitation, $code] = OrganizationInvitation::issue($f['org'], 'nowy@example.com', $f['owner'], UserRole::SHOP_OWNER, null, '+48501502503');

    $this->get(route('register', ['organization_invitation' => $invitation->code]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/register')
            ->where('organizationInvitation.email', 'nowy@example.com')
            ->where('organizationInvitation.phone', '+48501502503'));

    $this->post(route('register.store'), [
        'name' => 'Nowy', 'email' => 'nowy@example.com', 'phone' => '+48999999999',
        'password' => 'qwer1234qwer', 'password_confirmation' => 'qwer1234qwer',
        'terms' => '1', 'privacy' => '1',
        'organization_invitation' => $invitation->code, 'invitation_key' => $code,
    ]);

    expect(User::where('email', 'nowy@example.com')->first()->phone)->toBe('+48999999999');
});
