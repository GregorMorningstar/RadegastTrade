<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\OfferAttachment;
use App\Models\OfferItem;
use App\Models\OfferVersion;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\TradeInvitation;
use App\Models\TradeRelationship;
use App\Models\UnitOrderPermission;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Notifications\OfferSharedNotification;
use App\Notifications\OrderNotification;
use App\Notifications\TradeInvitationNotification;
use App\Services\OfferService;
use App\Services\OrderPermissionService;
use App\Services\TradeRelationshipService;
use App\Support\OrderVisibility;
use App\Support\UnitRef;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Notification::fake());

/**
 * Organization A sells (a warehouse headquarters with branches W1 and W2, a shop headquarters with a branch), organization
 * B buys (a shop headquarters with branches B1 and B2), organization C is a stranger.
 *
 * @return array<string, mixed>
 */
function tradeFixture(): array
{
    $a = Organization::create(['name' => 'Hurtownia A']);
    $b = Organization::create(['name' => 'Sklepy B']);
    $c = Organization::create(['name' => 'Obca C']);

    $hqW = WarehouseHeadquarter::create(['organization_id' => $a->id, 'name' => 'Hurtownia główna']);
    $w1 = WarehouseBranch::create(['organization_id' => $a->id, 'warehouse_headquarter_id' => $hqW->id, 'name' => 'Magazyn Jasło']);
    $w2 = WarehouseBranch::create(['organization_id' => $a->id, 'warehouse_headquarter_id' => $hqW->id, 'name' => 'Magazyn Krosno']);
    $hqSA = ShopHeadquarter::create(['organization_id' => $a->id, 'name' => 'Sklepy A']);
    $sa1 = ShopBranch::create(['organization_id' => $a->id, 'shop_headquarter_id' => $hqSA->id, 'name' => 'Sklep A Jasło']);

    $hqB = ShopHeadquarter::create(['organization_id' => $b->id, 'name' => 'Sklepy B']);
    $b1 = ShopBranch::create(['organization_id' => $b->id, 'shop_headquarter_id' => $hqB->id, 'name' => 'Sklep Rzeszów']);
    $b2 = ShopBranch::create(['organization_id' => $b->id, 'shop_headquarter_id' => $hqB->id, 'name' => 'Sklep Tarnów']);

    $hqC = ShopHeadquarter::create(['organization_id' => $c->id, 'name' => 'Sklepy C']);
    $c1 = ShopBranch::create(['organization_id' => $c->id, 'shop_headquarter_id' => $hqC->id, 'name' => 'Sklep C1']);

    $make = fn (UserRole $role, Organization $org, array $extra = []) => User::factory()->create(['role' => $role, 'organization_id' => $org->id, ...$extra]);

    return [
        'a' => $a, 'b' => $b, 'c' => $c,
        'hqW' => $hqW, 'w1' => $w1, 'w2' => $w2, 'hqSA' => $hqSA, 'sa1' => $sa1, 'hqB' => $hqB, 'b1' => $b1, 'b2' => $b2, 'hqC' => $hqC, 'c1' => $c1,
        'ownerA' => $make(UserRole::ORGANIZATION_OWNER, $a),
        'mgrW' => $make(UserRole::WAREHOUSE_OWNER, $a, ['warehouse_headquarter_id' => $hqW->id]),
        'leaderW1' => $make(UserRole::WAREHOUSE_LEADER, $a, ['warehouse_headquarter_id' => $hqW->id, 'warehouse_branch_id' => $w1->id]),
        'leaderW2' => $make(UserRole::WAREHOUSE_LEADER, $a, ['warehouse_headquarter_id' => $hqW->id, 'warehouse_branch_id' => $w2->id]),
        'workerW1' => $make(UserRole::WAREHOUSE_WORKER, $a, ['warehouse_headquarter_id' => $hqW->id, 'warehouse_branch_id' => $w1->id]),
        'leaderSA1' => $make(UserRole::SHOP_LEADER, $a, ['shop_headquarter_id' => $hqSA->id, 'shop_branch_id' => $sa1->id]),
        'workerSA1' => $make(UserRole::SHOP_WORKER, $a, ['shop_headquarter_id' => $hqSA->id, 'shop_branch_id' => $sa1->id]),
        'ownerB' => $make(UserRole::ORGANIZATION_OWNER, $b),
        'mgrB' => $make(UserRole::SHOP_OWNER, $b, ['shop_headquarter_id' => $hqB->id]),
        'leaderB1' => $make(UserRole::SHOP_LEADER, $b, ['shop_headquarter_id' => $hqB->id, 'shop_branch_id' => $b1->id]),
        'leaderB2' => $make(UserRole::SHOP_LEADER, $b, ['shop_headquarter_id' => $hqB->id, 'shop_branch_id' => $b2->id]),
        'workerB1' => $make(UserRole::SHOP_WORKER, $b, ['shop_headquarter_id' => $hqB->id, 'shop_branch_id' => $b1->id]),
        'ownerC' => $make(UserRole::ORGANIZATION_OWNER, $c),
        'leaderC1' => $make(UserRole::SHOP_LEADER, $c, ['shop_headquarter_id' => $hqC->id, 'shop_branch_id' => $c1->id]),
        'admin' => User::factory()->create(['role' => UserRole::Admin]),
    ];
}

function tref(Model $unit): string
{
    return UnitRef::of($unit)->ref();
}

/** @return array<int, array<string, mixed>> */
function tradeItems(string $price = '10.00'): array
{
    return [
        ['name' => 'Mleko 1l', 'sku' => 'MLK-1', 'unit_of_measure' => 'szt.', 'unit_price' => $price, 'vat_rate' => '23', 'min_qty' => '1', 'qty_step' => '1'],
        ['name' => 'Chleb', 'sku' => 'CHL-1', 'unit_of_measure' => 'szt.', 'unit_price' => '5.50', 'vat_rate' => '8', 'min_qty' => '2', 'qty_step' => '2', 'max_qty' => '20'],
    ];
}

/** An offer of a warehouse unit, published (optionally inside the organization) as the given manager. */
function tradeOffer(User $by, Model $unit, bool $publish = true, bool $internal = false, array $over = []): Offer
{
    $service = app(OfferService::class);

    $offer = $service->create($by, [
        'unit_ref' => tref($unit),
        'title' => 'Oferta jesienna',
        'currency' => 'PLN',
        'valid_to' => now()->addDays(5)->format('Y-m-d H:i:s'),
        'items' => tradeItems(),
        ...$over,
    ]);

    if ($publish) {
        $service->publish($by, $offer, $internal);
    }

    return $offer->refresh();
}

/** Cooperation A (supplier: W1) → B (buyer: B1 and B2), made through the real invitation flow. */
function tradeCooperate(array $f, ?array $supplierUnits = null, ?array $buyerUnits = null): TradeRelationship
{
    $service = app(TradeRelationshipService::class);

    $service->invite($f['ownerA'], ['inviter_role' => 'supplier', 'invitee_email' => $f['ownerB']->email, 'units' => $supplierUnits ?? [tref($f['w1'])]]);

    return $service->accept($f['ownerB'], tradeToken($f['ownerB']), ['units' => $buyerUnits ?? [tref($f['b1']), tref($f['b2'])]]);
}

/** The token from the (faked) e-mail of the last invitation sent to the person. */
function tradeToken(User $invitee): string
{
    $token = null;

    Notification::assertSentTo($invitee, TradeInvitationNotification::class, function (TradeInvitationNotification $notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    return $token;
}

function tradeShare(User $by, Offer $offer, Model ...$units): void
{
    app(OfferService::class)->share($by, $offer, array_map('tref', $units));
}

/** @return array<string, mixed> */
function tradePayload(Offer $offer, string $buyerRef, array $over = []): array
{
    $offer->load('currentVersion.items');
    $item = $offer->currentVersion->items->first();

    return [
        'offer_id' => $offer->id,
        'offer_version_id' => $offer->current_version_id,
        'buyer_unit' => $buyerRef,
        'items' => [['offer_item_id' => $item->id, 'qty' => '3']],
        'delivery_address' => ['name' => 'Sklep', 'street' => 'Długa 1', 'postal_code' => '35-001', 'city' => 'Jasło'],
        'idempotency_key' => Str::random(24),
        ...$over,
    ];
}


/**
 * Authorises a person to order for a unit through the real two steps: the request (a code goes to the e-mail of the person who
 * grants) and the confirmation with that code. A refused request is returned as it is.
 */
function tradeGrant(User $by, Model $unit, User $user): Illuminate\Testing\TestResponse
{
    $request = test()->actingAs($by)->post(route('trade.permissions.store'), ['unit' => tref($unit), 'user_id' => $user->id]);

    if ($request->status() !== 302 || $request->getSession()->has('errors')) {
        return $request;
    }

    $code = null;
    Notification::assertSentTo($by, App\Notifications\OrderPermissionCodeNotification::class, function ($notification) use (&$code) {
        $code = $notification->code;

        return true;
    });

    return test()->post(route('trade.permissions.confirm'), ['code' => $code]);
}

/** An offer shared with B1 and an order-ready buyer (leaderB1 orders for B1). */
function tradeReady(): array
{
    $f = tradeFixture();
    $f['relationship'] = tradeCooperate($f);
    $f['offer'] = tradeOffer($f['leaderW1'], $f['w1']);
    tradeShare($f['leaderW1'], $f['offer'], $f['b1']);

    return $f;
}

test('sklep widzi opublikowaną wewnętrznie ofertę magazynu tej samej organizacji', function () {
    $f = tradeFixture();
    $offer = tradeOffer($f['leaderW1'], $f['w1'], internal: true);

    foreach (['leaderSA1', 'workerSA1', 'ownerA', 'mgrW'] as $who) {
        $this->actingAs($f[$who])->get(route('trade.available.show', $offer->id))->assertOk();
    }

    $this->actingAs($f['leaderSA1'])->get(route('trade.available.index'))->assertOk()->assertInertia(fn ($page) => $page->has('offers', 1)->where('offers.0.number', $offer->number));

    // the exception covers the offer only: the person does not get at the structure or the people of the warehouse
    $this->actingAs($f['leaderSA1'])->get(route('trade.offers.show', $offer->id))->assertOk()->assertInertia(fn ($page) => $page->where('offer.is_seller', false)->missing('offer.shares'));
    $this->actingAs($f['leaderSA1'])->put(route('trade.offers.update', $offer->id), ['title' => 'x'])->assertForbidden();

    // an offer that is not published inside the organization stays invisible to the shop
    $private = tradeOffer($f['leaderW1'], $f['w1']);
    $this->actingAs($f['leaderSA1'])->get(route('trade.available.show', $private->id))->assertNotFound();
});

test('szkic oferty nie jest widoczny dla odbiorców, a nowa wersja robocza nie zmienia tego co widzą', function () {
    $f = tradeFixture();
    $draft = tradeOffer($f['leaderW1'], $f['w1'], publish: false);

    $this->actingAs($f['leaderSA1'])->get(route('trade.available.show', $draft->id))->assertNotFound();
    $this->actingAs($f['workerW1'])->get(route('trade.offers.show', $draft->id))->assertNotFound();
    $this->actingAs($f['leaderW1'])->get(route('trade.offers.show', $draft->id))->assertOk();

    $offer = tradeOffer($f['leaderW1'], $f['w1'], internal: true);
    app(OfferService::class)->updateDraft($f['leaderW1'], $offer, ['title' => 'Zupełnie nowy tytuł', 'currency' => 'PLN', 'items' => tradeItems('99.00')]);

    $this->actingAs($f['leaderSA1'])->get(route('trade.available.show', $offer->id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('offer.version.title', 'Oferta jesienna')->where('offer.version.version_no', 1)->missing('offer.draft'));
});

test('obca organizacja bez udostępnienia nie widzi oferty', function () {
    $f = tradeFixture();
    tradeCooperate($f);
    $offer = tradeOffer($f['leaderW1'], $f['w1'], internal: true);

    foreach (['ownerC', 'leaderC1'] as $who) {
        $this->actingAs($f[$who])->get(route('trade.available.show', $offer->id))->assertNotFound();
        $this->actingAs($f[$who])->get(route('trade.offers.show', $offer->id))->assertNotFound();
        $this->actingAs($f[$who])->get(route('trade.available.index'))->assertInertia(fn ($page) => $page->has('offers', 0));
    }

    // platform accounts do not trade at all
    $this->actingAs($f['admin'])->get(route('trade.available.index'))->assertForbidden();
});

test('przyjęcie współpracy nie otwiera dostępu do ofert dostawcy', function () {
    $f = tradeFixture();
    $relationship = tradeCooperate($f);
    $offer = tradeOffer($f['leaderW1'], $f['w1']);

    expect($relationship->isActive())->toBeTrue();

    foreach (['ownerB', 'mgrB', 'leaderB1', 'leaderB2'] as $who) {
        $this->actingAs($f[$who])->get(route('trade.available.show', $offer->id))->assertNotFound();
        $this->actingAs($f[$who])->get(route('trade.available.index'))->assertInertia(fn ($page) => $page->has('offers', 0));
    }
});

test('po udostępnieniu dostęp ma konkretna placówka, a sąsiednia nie', function () {
    $f = tradeReady();

    $this->actingAs($f['leaderB1'])->get(route('trade.available.show', $f['offer']->id))->assertOk();
    $this->actingAs($f['leaderB1'])->get(route('trade.available.index'))->assertInertia(fn ($page) => $page->has('offers', 1));

    // the neighbouring branch of the same organization, in the scope of the cooperation but not shared
    $this->actingAs($f['leaderB2'])->get(route('trade.available.show', $f['offer']->id))->assertNotFound();
    $this->actingAs($f['leaderB2'])->get(route('trade.available.index'))->assertInertia(fn ($page) => $page->has('offers', 0));

    // the recipient sees neither the recipients nor the draft, and may not change anything
    $this->actingAs($f['leaderB1'])->get(route('trade.offers.show', $f['offer']->id))->assertOk()->assertInertia(fn ($page) => $page->where('offer.is_seller', false)->missing('offer.shares')->missing('offer.draft'));
    $this->actingAs($f['leaderB1'])->post(route('trade.offers.withdraw', $f['offer']->id))->assertForbidden();

    Notification::assertSentTo($f['leaderB1'], OfferSharedNotification::class);
    Notification::assertNotSentTo($f['leaderB2'], OfferSharedNotification::class);
});

test('udostępnienie jednostce spoza zakresu współpracy jest odrzucone', function () {
    $f = tradeFixture();
    tradeCooperate($f, buyerUnits: [tref($f['b1'])]);
    $offer = tradeOffer($f['leaderW1'], $f['w1']);
    $service = app(OfferService::class);

    // b2 is not in the scope, c1 belongs to a stranger, w2's offer is not in the supplier scope
    expect(fn () => $service->share($f['leaderW1'], $offer, [tref($f['b2'])]))->toThrow(ValidationException::class);
    expect(fn () => $service->share($f['leaderW1'], $offer, [tref($f['c1'])]))->toThrow(ValidationException::class);

    $other = tradeOffer($f['leaderW2'], $f['w2']);
    expect(fn () => $service->share($f['leaderW2'], $other, [tref($f['b1'])]))->toThrow(ValidationException::class);
    expect(fn () => $service->share($f['leaderW2'], $offer, [tref($f['b1'])]))->toThrow(AuthorizationException::class);
});

test('przełożony widzi oferty udostępnione jednostkom pod nim zgodnie z hierarchią', function () {
    $f = tradeReady();

    foreach (['mgrB', 'ownerB', 'workerB1'] as $who) {
        $this->actingAs($f[$who])->get(route('trade.available.show', $f['offer']->id))->assertOk();
    }

    // a manager of the supplier sees the offers of the units below them, but not those of another branch
    $other = tradeOffer($f['leaderW2'], $f['w2']);
    $this->actingAs($f['mgrW'])->get(route('trade.offers.show', $other->id))->assertOk()->assertInertia(fn ($page) => $page->where('offer.is_seller', true));
    $this->actingAs($f['leaderW1'])->get(route('trade.offers.show', $other->id))->assertNotFound();
});

test('akceptacja zaproszenia wymaga reprezentowania organizacji', function () {
    $f = tradeFixture();
    $service = app(TradeRelationshipService::class);
    $service->invite($f['ownerA'], ['inviter_role' => 'supplier', 'invitee_email' => $f['ownerB']->email, 'units' => [tref($f['w1'])]]);
    $token = tradeToken($f['ownerB']);
    $accept = route('trade.invitations.accept', $token);

    // a guest, a person without the right to represent, a person of another organization: none of them can accept
    $this->post($accept, ['units' => [tref($f['b1'])]])->assertRedirect(route('login'));
    $this->actingAs($f['workerB1'])->post($accept, ['units' => [tref($f['b1'])]])->assertForbidden();
    $this->actingAs($f['ownerC'])->post($accept, ['units' => [tref($f['b1'])]])->assertForbidden();
    $this->actingAs($f['ownerA'])->post($accept, ['units' => [tref($f['b1'])]])->assertForbidden();
    expect(TradeRelationship::count())->toBe(0);

    // the link alone gives nothing: the worker sees the page but not the choice
    $this->actingAs($f['workerB1'])->get(route('trade.invitations.show', $token))->assertOk()->assertInertia(fn ($page) => $page->where('invitation.represents', false)->where('invitation.units', []));

    // a branch leader cannot take a unit they do not manage, nor all units of the organization
    $this->actingAs($f['leaderB1'])->post($accept, ['units' => [tref($f['b2'])]])->assertSessionHasErrors('units');
    $this->actingAs($f['leaderB1'])->post($accept, ['all_current' => true])->assertSessionHasErrors('units');
    $this->actingAs($f['leaderB1'])->post($accept, ['units' => [tref($f['c1'])]])->assertSessionHasErrors('units');
    expect(TradeRelationship::count())->toBe(0);

    // the leader accepts for their branch only: the cooperation covers B1 and nothing else of organization B
    $this->actingAs($f['leaderB1'])->post($accept, ['units' => [tref($f['b1'])]])->assertRedirect(route('trade.partners.index'));

    $relationship = TradeRelationship::sole();
    expect($relationship->units()->where('side', 'buyer')->pluck('unit_ref')->all())->toBe([tref($f['b1'])])
        ->and($relationship->units()->where('side', 'supplier')->pluck('unit_ref')->all())->toBe([tref($f['w1'])])
        ->and($f['leaderB1']->fresh()->organization_id)->toBe($f['b']->id);
});

test('zaproszenie wygasłe, cofnięte lub wykorzystane jest odrzucane', function () {
    $f = tradeFixture();
    $service = app(TradeRelationshipService::class);
    $invite = fn () => $service->invite($f['ownerA'], ['inviter_role' => 'supplier', 'invitee_email' => $f['ownerB']->email, 'units' => [tref($f['w1'])]]);
    $accept = fn (string $token) => $this->actingAs($f['ownerB'])->post(route('trade.invitations.accept', $token), ['units' => [tref($f['b1'])]]);

    // withdrawn
    $first = $invite();
    $token = tradeToken($f['ownerB']);
    $this->actingAs($f['ownerA'])->delete(route('trade.partners.invitations.cancel', $first->id))->assertRedirect();
    $accept($token)->assertSessionHasErrors('invitation');

    // expired
    $second = $invite();
    $token = tradeToken($f['ownerB']);
    TradeInvitation::whereKey($second->id)->update(['expires_at' => now()->subMinute()]);
    $accept($token)->assertSessionHasErrors('invitation');
    expect($second->fresh()->effectiveStatus()->value)->toBe('expired');

    // used (one-time)
    $third = $invite();
    $token = tradeToken($f['ownerB']);
    $accept($token)->assertRedirect(route('trade.partners.index'));
    $accept($token)->assertSessionHasErrors('invitation');
    expect(TradeRelationship::count())->toBe(1)->and($third->fresh()->status->value)->toBe('accepted');

    // an unknown or malformed token
    $this->actingAs($f['ownerB'])->get(route('trade.invitations.show', Str::random(64)))->assertNotFound();
    $this->actingAs($f['ownerB'])->post(route('trade.invitations.accept', 'abc'), ['units' => [tref($f['b1'])]])->assertNotFound();
});

test('zaproszenie nie ujawnia, czy adres jest w systemie, i wiąże tylko istniejące organizacje', function () {
    $f = tradeFixture();

    $this->actingAs($f['ownerA'])->post(route('trade.partners.invite'), ['inviter_role' => 'supplier', 'invitee_email' => 'nikt@example.com', 'units' => [tref($f['w1'])]])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($f['ownerA'])->post(route('trade.partners.invite'), ['inviter_role' => 'supplier', 'invitee_email' => $f['ownerB']->email, 'units' => [tref($f['w1'])]])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(TradeInvitation::count())->toBe(1);
    Notification::assertSentTo($f['ownerB'], TradeInvitationNotification::class);

    // the token is stored only as a hash
    expect(TradeInvitation::sole()->token_hash)->toBe(TradeInvitation::hashToken(tradeToken($f['ownerB'])))->and(strlen(tradeToken($f['ownerB'])))->toBe(64);
});

test('lider i kierownik zapraszają tylko w zakresie swoich jednostek, a oferty przedstawiają tylko hurtownie', function () {
    $f = tradeFixture();
    $service = app(TradeRelationshipService::class);
    $base = ['inviter_role' => 'supplier', 'invitee_email' => $f['ownerB']->email];

    // the leader of W1 cannot offer W2, a shop, or everything
    expect(fn () => $service->invite($f['leaderW1'], [...$base, 'units' => [tref($f['w2'])]]))->toThrow(ValidationException::class);
    expect(fn () => $service->invite($f['leaderW1'], [...$base, 'all_current' => true]))->toThrow(ValidationException::class);
    expect(fn () => $service->invite($f['leaderSA1'], [...$base, 'units' => [tref($f['sa1'])]]))->toThrow(ValidationException::class);
    // a worker does not invite at all
    expect(fn () => $service->invite($f['workerW1'], [...$base, 'units' => [tref($f['w1'])]]))->toThrow(AuthorizationException::class);
    expect(TradeInvitation::count())->toBe(0);

    // a shop may ask a warehouse for offers (the shop is the buyer side), for its own unit
    $service->invite($f['leaderSA1'], ['inviter_role' => 'buyer', 'invitee_email' => $f['ownerB']->email, 'units' => [tref($f['sa1'])]]);
    expect(TradeInvitation::sole()->inviter_role->value)->toBe('buyer');

    // the owner may name all current units: on the supplier side that means the warehouses only
    $other = Organization::create(['name' => 'Inna']);
    $otherOwner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $other->id]);
    $invitation = $service->invite($f['ownerA'], ['inviter_role' => 'supplier', 'invitee_email' => $otherOwner->email, 'all_current' => true]);

    expect($invitation->units()->pluck('unit_ref')->sort()->values()->all())->toBe(collect([tref($f['hqW']), tref($f['w1']), tref($f['w2'])])->sort()->values()->all());
});

test('wyznaczony pracownik składa zamówienie w imieniu swojej jednostki', function () {
    $f = tradeReady();

    // the leader of B1 authorises the worker for B1
    tradeGrant($f['leaderB1'], $f['b1'], $f['workerB1'])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($f['workerB1'])->get(route('trade.available.show', $f['offer']->id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('offer.order_units.0.ref', tref($f['b1'])));

    $this->actingAs($f['workerB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertRedirect();

    $order = Order::sole();

    expect($order->status->value)->toBe('submitted')
        ->and($order->placed_by)->toBe($f['workerB1']->id)
        ->and($order->buyer_organization_id)->toBe($f['b']->id)->and($order->buyer_unit_ref)->toBe(tref($f['b1']))->and($order->buyer_unit_name)->toBe('Sklep Rzeszów')
        ->and($order->seller_organization_id)->toBe($f['a']->id)->and($order->seller_unit_ref)->toBe(tref($f['w1']))
        ->and($order->offer_version_id)->toBe($f['offer']->current_version_id)->and($order->relationship_id)->toBe($f['relationship']->id)
        ->and($order->history()->count())->toBe(1);

    // the seller is told; the buyer sees the order, the seller sees it among the received ones
    Notification::assertSentTo($f['leaderW1'], OrderNotification::class);
    $this->actingAs($f['workerB1'])->get(route('trade.orders.show', $order->id))->assertOk();
    $this->actingAs($f['leaderW1'])->get(route('trade.orders.index', ['kind' => 'sales']))->assertInertia(fn ($page) => $page->has('orders', 1));
});

test('pracownik bez uprawnienia nie złoży zamówienia, a przełożony tak', function () {
    $f = tradeReady();

    $this->actingAs($f['workerB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertForbidden();
    $this->actingAs($f['workerB1'])->get(route('trade.available.show', $f['offer']->id))->assertOk()->assertInertia(fn ($page) => $page->where('offer.order_units', []));
    expect(Order::count())->toBe(0);

    foreach (['leaderB1', 'mgrB', 'ownerB'] as $who) {
        $this->actingAs($f[$who])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertRedirect();
    }

    expect(Order::count())->toBe(3);

    // the leader of the neighbouring branch cannot order for B1
    $this->actingAs($f['leaderB2'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertForbidden();
});

test('nie można zamawiać dla obcej jednostki ani dla jednostki bez udostępnienia', function () {
    $f = tradeReady();
    UnitOrderPermission::query()->count();
    tradeGrant($f['leaderB1'], $f['b1'], $f['workerB1']);

    // authorised for B1 only: B2 (same organization, not authorised), a unit of a stranger and nonsense are refused
    foreach ([tref($f['b2']), tref($f['c1']), tref($f['sa1']), 'shops:branches:999999', 'nonsense'] as $ref) {
        $this->actingAs($f['workerB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], $ref))->assertForbidden();
    }

    // a stranger cannot order for B1 even by naming it
    $this->actingAs($f['leaderC1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertForbidden();
    $this->actingAs($f['ownerC'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertForbidden();

    // B2's own manager may order for B2, but the offer was not shared with B2: for them it does not exist
    $this->actingAs($f['leaderB2'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b2'])))->assertNotFound();
    expect(Order::count())->toBe(0);
});

test('nie można zamówić z oferty wygasłej ani wycofanej', function () {
    $f = tradeReady();
    $payload = tradePayload($f['offer'], tref($f['b1']));

    $this->actingAs($f['leaderW1'])->post(route('trade.offers.withdraw', $f['offer']->id))->assertRedirect();
    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), $payload)->assertSessionHasErrors('offer');

    // expired: the status is worked out from the validity date
    $offer = tradeOffer($f['leaderW1'], $f['w1']);
    tradeShare($f['leaderW1'], $offer, $f['b1']);
    $payload = tradePayload($offer, tref($f['b1']));

    $this->travel(6)->days();

    expect($offer->fresh()->load('currentVersion')->displayStatus())->toBe('expired');
    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), $payload)->assertSessionHasErrors('offer');
    $this->actingAs($f['leaderB1'])->get(route('trade.available.index'))->assertInertia(fn ($page) => $page->has('offers', 0));
    $this->actingAs($f['leaderW1'])->get(route('trade.offers.index', ['status' => 'expired']))->assertInertia(fn ($page) => $page->has('offers', 1));
    expect(Order::count())->toBe(0);
});

test('cena i kwoty pochodzą z oferty, a nie z danych przesłanych przez frontend', function () {
    $f = tradeReady();

    $payload = tradePayload($f['offer'], tref($f['b1']), [
        'unit_price' => '0.01', 'total' => '0.01', 'subtotal' => '0.01',
        'organization_id' => $f['a']->id, 'buyer_organization_id' => $f['a']->id, 'seller_organization_id' => $f['b']->id,
    ]);
    $payload['items'][0]['unit_price'] = '0.01';
    $payload['items'][0]['vat_rate'] = '0';

    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), $payload)->assertRedirect();

    $order = Order::sole()->load('items');

    // 3 × 10.00 net, 23% VAT: 30.00 + 6.90
    expect((string) $order->subtotal)->toBe('30.00')->and((string) $order->tax_total)->toBe('6.90')->and((string) $order->total)->toBe('36.90')
        ->and((string) $order->items[0]->unit_price)->toBe('10.0000')->and((string) $order->items[0]->vat_rate)->toBe('23.00')
        ->and($order->buyer_organization_id)->toBe($f['b']->id)->and($order->seller_organization_id)->toBe($f['a']->id);

    // quantities follow the rules of the offer: Chleb has min 2, step 2, max 20
    $chleb = $f['offer']->currentVersion->items->last();

    foreach (['1', '3', '22', '0', '-2', 'abc', '2.0005'] as $qty) {
        $bad = tradePayload($f['offer'], tref($f['b1']), ['items' => [['offer_item_id' => $chleb->id, 'qty' => $qty]]]);
        $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), $bad)->assertSessionHasErrors();
    }

    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1']), ['items' => [['offer_item_id' => $chleb->id, 'qty' => '4']]]))->assertRedirect();
    expect(Order::count())->toBe(2);
});

test('historyczna cena zostaje po zmianie oferty, a opublikowana wersja jest niezmienna', function () {
    $f = tradeReady();
    $service = app(OfferService::class);
    $v1 = $f['offer']->currentVersion;

    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertRedirect();
    $order = Order::sole();

    // the supplier raises the price: a new version, the old one stays as it was
    $service->updateDraft($f['leaderW1'], $f['offer'], ['title' => 'Oferta jesienna', 'currency' => 'PLN', 'valid_to' => now()->addDays(5)->format('Y-m-d H:i:s'), 'items' => tradeItems('99.00')]);
    $service->publish($f['leaderW1'], $f['offer']->refresh());
    $v2 = $f['offer']->refresh()->currentVersion;

    expect($v2->id)->not->toBe($v1->id)->and($v2->version_no)->toBe(2)->and($v1->fresh()->status->value)->toBe('superseded')
        ->and((string) $order->fresh()->items()->first()->unit_price)->toBe('10.0000')->and($order->fresh()->offer_version_id)->toBe($v1->id);

    // a frozen version and its positions cannot be changed or deleted, whatever the code path
    expect(fn () => $v1->fresh()->update(['title' => 'Podmiana']))->toThrow(LogicException::class);
    expect(fn () => OfferItem::query()->where('offer_version_id', $v1->id)->first()->update(['unit_price' => '0.01']))->toThrow(LogicException::class);
    expect(fn () => OfferItem::query()->where('offer_version_id', $v1->id)->first()->delete())->toThrow(LogicException::class);
    expect(fn () => $v2->delete())->toThrow(LogicException::class);

    // an order for the old version is refused: the buyer has to see the new prices
    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1']), ['offer_version_id' => $v1->id]))->assertSessionHasErrors('offer');
    // the share carries on with the new version
    $this->actingAs($f['leaderB1'])->get(route('trade.available.show', $f['offer']->id))->assertInertia(fn ($page) => $page->where('offer.version.version_no', 2));
});

test('zawieszenie współpracy blokuje nowe zamówienia i udostępnienia, ale nie rusza istniejących', function () {
    $f = tradeReady();

    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertRedirect();
    $relationship = $f['relationship'];

    // only people who manage the whole scope of their side may suspend: W2's leader is not even a party's manager of W1
    $this->actingAs($f['leaderW2'])->post(route('trade.partners.suspend', $relationship->id))->assertForbidden();
    $this->actingAs($f['workerW1'])->post(route('trade.partners.suspend', $relationship->id))->assertForbidden();
    $this->actingAs($f['ownerC'])->post(route('trade.partners.suspend', $relationship->id))->assertNotFound();
    $this->actingAs($f['leaderW1'])->post(route('trade.partners.suspend', $relationship->id), ['reason' => 'Spór o płatności'])->assertRedirect();

    expect($relationship->fresh()->status->value)->toBe('suspended');

    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertSessionHasErrors('offer');
    $this->actingAs($f['leaderB1'])->get(route('trade.available.show', $f['offer']->id))->assertInertia(fn ($page) => $page->where('offer.order_units', []));
    expect(fn () => tradeShare($f['leaderW1'], tradeOffer($f['leaderW1'], $f['w1']), $f['b2']))->toThrow(ValidationException::class);

    // the existing order is untouched and the offer stays readable
    expect(Order::sole()->status->value)->toBe('submitted');
    $this->actingAs($f['leaderB1'])->get(route('trade.available.show', $f['offer']->id))->assertOk();

    $this->actingAs($f['leaderW1'])->post(route('trade.partners.resume', $relationship->id))->assertRedirect();
    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertRedirect();
    expect(Order::count())->toBe(2);
});

test('zakończenie współpracy zachowuje historię i zamówienia', function () {
    $f = tradeReady();

    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertRedirect();
    $order = Order::sole();

    $this->actingAs($f['ownerB'])->post(route('trade.partners.end', $f['relationship']->id))->assertRedirect();
    expect($f['relationship']->fresh()->status->value)->toBe('ended')->and($f['relationship']->fresh()->ended_by)->toBe($f['ownerB']->id);

    // history stays readable on both sides; new orders and new shares are blocked; an ended cooperation cannot be resumed
    $this->actingAs($f['leaderB1'])->get(route('trade.orders.show', $order->id))->assertOk();
    $this->actingAs($f['leaderB1'])->get(route('trade.orders.index'))->assertInertia(fn ($page) => $page->has('orders', 1));
    $this->actingAs($f['leaderW1'])->get(route('trade.orders.index', ['kind' => 'sales']))->assertInertia(fn ($page) => $page->has('orders', 1));
    $this->actingAs($f['leaderB1'])->get(route('trade.available.show', $f['offer']->id))->assertOk();
    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertSessionHasErrors('offer');
    $this->actingAs($f['ownerA'])->post(route('trade.partners.resume', $f['relationship']->id))->assertSessionHasErrors('relationship');
    expect(Order::sole()->status->value)->toBe('submitted')->and(AuditLog::where('action', 'trade.relationship.ended')->count())->toBe(1);
});

test('załączniki są chronione: pobierają je tylko osoby z dostępem do oferty', function () {
    Storage::fake('local');
    $f = tradeFixture();
    tradeCooperate($f);
    $offer = tradeOffer($f['leaderW1'], $f['w1'], publish: false);

    $upload = fn (User $by) => $this->actingAs($by)->post(route('trade.offers.attachments.store', $offer->id), ['file' => UploadedFile::fake()->create('cennik.pdf', 20, 'application/pdf')]);

    // only a manager of the unit attaches (a shop leader and a stranger are refused, a bad type too)
    $upload($f['leaderSA1'])->assertNotFound();
    $upload($f['ownerC'])->assertNotFound();
    $this->actingAs($f['leaderW1'])->post(route('trade.offers.attachments.store', $offer->id), ['file' => UploadedFile::fake()->create('virus.exe', 20, 'application/octet-stream')])->assertSessionHasErrors('file');
    $upload($f['leaderW1'])->assertRedirect()->assertSessionHasNoErrors();

    $attachment = OfferAttachment::sole();
    $download = route('trade.attachments.download', $attachment->id);

    // a draft's file: only the managers
    $this->actingAs($f['leaderW1'])->get($download)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($f['mgrW'])->get($download)->assertOk();
    $this->actingAs($f['leaderB1'])->get($download)->assertNotFound();

    app(OfferService::class)->publish($f['leaderW1'], $offer);
    tradeShare($f['leaderW1'], $offer->refresh(), $f['b1']);

    // published and shared: the recipient downloads; the neighbouring branch, a stranger, a guest and the other warehouse do not
    $this->actingAs($f['leaderB1'])->get($download)->assertOk();
    $this->actingAs($f['mgrB'])->get($download)->assertOk();
    $this->actingAs($f['leaderB2'])->get($download)->assertNotFound();
    $this->actingAs($f['ownerC'])->get($download)->assertNotFound();
    $this->actingAs($f['leaderW2'])->get($download)->assertNotFound();
    auth()->logout();
    $this->get($download)->assertRedirect(route('login'));

    // a file added to a later draft is not for the recipient until that version is published
    $this->actingAs($f['leaderW1'])->post(route('trade.offers.attachments.store', $offer->id), ['file' => UploadedFile::fake()->create('nowy.pdf', 20, 'application/pdf')])->assertRedirect();
    $draftFile = OfferAttachment::query()->latest('id')->first();
    $this->actingAs($f['leaderB1'])->get(route('trade.attachments.download', $draftFile->id))->assertNotFound();
    $this->actingAs($f['leaderW1'])->get(route('trade.attachments.download', $draftFile->id))->assertOk();
});

test('to samo żądanie zamówienia wysłane dwa razy daje jedno zamówienie', function () {
    $f = tradeReady();
    $payload = tradePayload($f['offer'], tref($f['b1']));

    $first = $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), $payload);
    $second = $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), $payload);

    expect(Order::count())->toBe(1);
    $first->assertRedirect(route('trade.orders.show', Order::sole()->id));
    $second->assertRedirect(route('trade.orders.show', Order::sole()->id));

    // somebody else cannot use the key of another person's request
    $this->actingAs($f['mgrB'])->post(route('trade.orders.store'), $payload)->assertSessionHasErrors('idempotency_key');
    expect(Order::count())->toBe(1);
});

test('podmiana organizacji, jednostki i identyfikatora oferty nie działa', function () {
    $f = tradeReady();
    $hidden = tradeOffer($f['leaderW1'], $f['w1']);
    $otherItem = $hidden->currentVersion->items->first();

    // an offer that was not shared is a 404, however the form is changed
    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($hidden, tref($f['b1'])))->assertNotFound();
    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1']), ['offer_id' => 999999]))->assertNotFound();

    // a position of another offer put into the order
    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1']), ['items' => [['offer_item_id' => $otherItem->id, 'qty' => '1']]]))->assertSessionHasErrors('items');

    // the unit of the buyer from another organization, and organization ids in the form
    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['sa1'])))->assertForbidden();
    $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1']), ['organization_id' => $f['a']->id, 'buyer_organization_id' => $f['c']->id]))->assertRedirect();

    expect(Order::sole()->buyer_organization_id)->toBe($f['b']->id);

    // other people's records are not found by their ids
    $order = Order::sole();
    $this->actingAs($f['ownerC'])->get(route('trade.orders.show', $order->id))->assertNotFound();
    $this->actingAs($f['leaderB2'])->get(route('trade.orders.show', $order->id))->assertNotFound();
    $this->actingAs($f['ownerC'])->post(route('trade.orders.transition', $order->id), ['to' => 'cancelled'])->assertNotFound();
    $this->actingAs($f['ownerC'])->post(route('trade.offers.share', $f['offer']->id), ['units' => [tref($f['c1'])]])->assertNotFound();
});

test('zamówienie przechodzi statusy: sprzedawca potwierdza i wysyła, sklep anuluje lub potwierdza odbiór', function () {
    $f = tradeReady();
    $place = function () use ($f) {
        $this->actingAs($f['leaderB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertRedirect();

        return Order::query()->latest('id')->first();
    };
    $move = fn (User $by, Order $order, string $to, array $extra = []) => $this->actingAs($by)->post(route('trade.orders.transition', $order->id), ['to' => $to, ...$extra]);

    $order = $place();

    // the buyer cannot confirm its own order, a warehouse worker without rights does not even see it, a stranger neither
    $move($f['leaderB1'], $order, 'confirmed')->assertForbidden();
    $move($f['workerW1'], $order, 'confirmed')->assertNotFound();
    $move($f['leaderW2'], $order, 'confirmed')->assertNotFound();
    $move($f['leaderB1'], $order, 'received')->assertSessionHasErrors('status');

    // the seller confirms (placing and confirming are two steps), then fulfils
    $move($f['leaderW1'], $order, 'confirmed')->assertRedirect();
    expect($order->fresh()->status->value)->toBe('confirmed');
    $move($f['leaderW1'], $order, 'received')->assertSessionHasErrors('status');
    $move($f['leaderW1'], $order, 'fulfilled')->assertRedirect();
    $move($f['leaderW1'], $order, 'cancelled')->assertSessionHasErrors('status');

    // the buyer confirms the receipt; after that nothing changes
    $move($f['leaderW1'], $order, 'received')->assertForbidden();
    $move($f['leaderB1'], $order, 'received')->assertRedirect();
    $move($f['leaderB1'], $order, 'cancelled')->assertSessionHasErrors('status');
    expect($order->fresh()->status->value)->toBe('received')->and($order->history()->pluck('to_status')->map->value->all())->toBe(['submitted', 'confirmed', 'fulfilled', 'received']);

    // rejecting needs a reason; the shop may cancel before fulfilment (also a confirmed order)
    $second = $place();
    $move($f['mgrW'], $second, 'rejected')->assertSessionHasErrors('reason');
    $move($f['mgrW'], $second, 'rejected', ['reason' => 'Brak towaru'])->assertRedirect();
    expect($second->fresh()->status->value)->toBe('rejected');

    $third = $place();
    $move($f['leaderB1'], $third, 'cancelled')->assertRedirect();
    $fourth = $place();
    $move($f['ownerA'], $fourth, 'confirmed')->assertRedirect();
    $move($f['mgrB'], $fourth, 'cancelled')->assertRedirect();
    expect($third->fresh()->status->value)->toBe('cancelled')->and($fourth->fresh()->status->value)->toBe('cancelled');
    Notification::assertSentTo($f['leaderB1'], OrderNotification::class);
});

test('sklep zamawia z oferty wewnętrznej magazynu bez relacji handlowej, tylko gdy oferta jest udostępniona w organizacji', function () {
    $f = tradeFixture();
    $offer = tradeOffer($f['leaderW1'], $f['w1'], internal: true);

    // looking is not ordering: the shop leader orders for their own unit, the worker only after being authorised
    $this->actingAs($f['workerSA1'])->post(route('trade.orders.store'), tradePayload($offer, tref($f['sa1'])))->assertForbidden();
    $this->actingAs($f['leaderSA1'])->post(route('trade.orders.store'), tradePayload($offer, tref($f['sa1'])))->assertRedirect();

    $order = Order::sole();
    expect($order->relationship_id)->toBeNull()->and($order->seller_organization_id)->toBe($f['a']->id)->and($order->buyer_organization_id)->toBe($f['a']->id);

    // a unit cannot order from itself, and an offer not published inside the organization cannot be ordered from
    $this->actingAs($f['leaderW1'])->post(route('trade.orders.store'), tradePayload($offer, tref($f['w1'])))->assertSessionHasErrors('buyer_unit');
    app(OfferService::class)->setInternal($f['leaderW1'], $offer, false);
    $this->actingAs($f['leaderSA1'])->post(route('trade.orders.store'), tradePayload($offer->refresh(), tref($f['sa1'])))->assertNotFound();
});

test('uprawnienie do zamawiania można nadać tylko w swoim zakresie i cofnąć z zapisem kto i kiedy', function () {
    $f = tradeReady();
    $grant = fn (User $by, Model $unit, User $user) => tradeGrant($by, $unit, $user);

    // the leader of B2 cannot delegate for B1; a worker cannot delegate; a person of another organization cannot be authorised
    $grant($f['leaderB2'], $f['b1'], $f['workerB1'])->assertForbidden();
    $grant($f['workerB1'], $f['b1'], $f['workerB1'])->assertForbidden();
    $grant($f['leaderB1'], $f['b1'], $f['leaderC1'])->assertSessionHasErrors('user_id');
    $grant($f['leaderB1'], $f['b1'], $f['admin'])->assertSessionHasErrors('user_id');
    $this->actingAs($f['leaderB1'])->post(route('trade.permissions.store'), ['unit' => tref($f['c1']), 'user_id' => $f['workerB1']->id])->assertNotFound();
    expect(UnitOrderPermission::count())->toBe(0);

    // the manager of the headquarters delegates for a branch below; granting twice is one permission
    $grant($f['mgrB'], $f['b1'], $f['workerB1'])->assertRedirect();
    $grant($f['mgrB'], $f['b1'], $f['workerB1'])->assertRedirect();
    $permission = UnitOrderPermission::sole();
    expect($permission->granted_by)->toBe($f['mgrB']->id)->and($permission->revoked_at)->toBeNull()->and($permission->ability->value)->toBe('place_orders');

    $this->actingAs($f['workerB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertRedirect();

    // the panel lists who is authorised; only a manager may revoke
    $this->actingAs($f['leaderB1'])->get(route('trade.permissions.index'))->assertOk()->assertInertia(fn ($page) => $page->where('units.0.authorised.0.user', $f['workerB1']->name));
    $this->actingAs($f['leaderB2'])->delete(route('trade.permissions.destroy', $permission->id))->assertForbidden();
    $this->actingAs($f['workerB1'])->get(route('trade.permissions.index'))->assertForbidden();
    $this->actingAs($f['leaderB1'])->delete(route('trade.permissions.destroy', $permission->id))->assertRedirect();

    // revoked: the history stays (who, when), the right is gone
    $permission->refresh();
    expect($permission->revoked_at)->not->toBeNull()->and($permission->revoked_by)->toBe($f['leaderB1']->id)->and($permission->active_key)->toBeNull();
    $this->actingAs($f['workerB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertForbidden();

    // the same person can be authorised again, as a new record
    $grant($f['leaderB1'], $f['b1'], $f['workerB1'])->assertRedirect();
    expect(UnitOrderPermission::count())->toBe(2);

    // a deactivated person loses the right at once
    $f['workerB1']->forceFill(['is_active' => false])->save();
    expect(app(OrderVisibility::class)->canPlaceFor($f['workerB1']->fresh(), UnitRef::of($f['b1'])))->toBeFalse();
});

test('oferty tworzą tylko hurtownie, które dana osoba obsługuje', function () {
    $f = tradeFixture();
    $body = fn (Model $unit) => ['unit_ref' => tref($unit), 'title' => 'Oferta', 'currency' => 'PLN', 'items' => tradeItems()];

    // a shop only buys; a worker and a stranger have no rights; a leader cannot present from another branch
    $this->actingAs($f['leaderSA1'])->post(route('trade.offers.store'), $body($f['sa1']))->assertForbidden();
    $this->actingAs($f['workerW1'])->post(route('trade.offers.store'), $body($f['w1']))->assertForbidden();
    $this->actingAs($f['ownerC'])->post(route('trade.offers.store'), $body($f['w1']))->assertForbidden();
    $this->actingAs($f['leaderW1'])->post(route('trade.offers.store'), $body($f['w2']))->assertSessionHasErrors('unit_ref');
    $this->actingAs($f['ownerA'])->post(route('trade.offers.store'), $body($f['sa1']))->assertSessionHasErrors('unit_ref');
    expect(Offer::count())->toBe(0);

    $this->actingAs($f['leaderW1'])->post(route('trade.offers.store'), $body($f['w1']))->assertRedirect();
    $this->actingAs($f['mgrW'])->post(route('trade.offers.store'), $body($f['w2']))->assertRedirect();
    $this->actingAs($f['ownerA'])->post(route('trade.offers.store'), $body($f['hqW']))->assertRedirect();

    expect(Offer::count())->toBe(3)->and(Offer::query()->pluck('number')->unique()->count())->toBe(3);

    // bad data: no positions, a zero price, a negative quantity
    $this->actingAs($f['leaderW1'])->post(route('trade.offers.store'), [...$body($f['w1']), 'items' => []])->assertSessionHasErrors('items');
    $this->actingAs($f['leaderW1'])->post(route('trade.offers.store'), [...$body($f['w1']), 'items' => [[...tradeItems()[0], 'unit_price' => '0']]])->assertSessionHasErrors('items.0.unit_price');
    $this->actingAs($f['leaderW1'])->post(route('trade.offers.store'), [...$body($f['w1']), 'items' => [[...tradeItems()[0], 'min_qty' => '-1']]])->assertSessionHasErrors('items.0.min_qty');
});

test('publikacja, wycofanie i archiwizacja oferty oraz dziennik audytowy', function () {
    $f = tradeFixture();
    $offer = tradeOffer($f['leaderW1'], $f['w1'], publish: false);

    // an empty offer or one with a past date is not published
    $empty = app(OfferService::class)->create($f['leaderW1'], ['unit_ref' => tref($f['w1']), 'title' => 'Pusta', 'currency' => 'PLN', 'items' => tradeItems()]);
    OfferItem::query()->where('offer_version_id', $empty->versions()->first()->id)->delete();
    expect(fn () => app(OfferService::class)->publish($f['leaderW1'], $empty))->toThrow(ValidationException::class);

    $this->actingAs($f['leaderW2'])->post(route('trade.offers.publish', $offer->id))->assertNotFound();
    $this->actingAs($f['leaderW1'])->post(route('trade.offers.publish', $offer->id), ['shared_internally' => true])->assertRedirect();

    $offer->refresh();
    $version = OfferVersion::query()->where('offer_id', $offer->id)->sole();
    expect($offer->status->value)->toBe('published')->and($offer->shared_internally)->toBeTrue()->and($version->status->value)->toBe('published')
        ->and($version->draft_key)->toBeNull()->and(strlen($version->content_hash))->toBe(64)->and($version->published_by)->toBe($f['leaderW1']->id);

    // a published offer cannot be archived before it is withdrawn
    $this->actingAs($f['leaderW1'])->post(route('trade.offers.archive', $offer->id))->assertSessionHasErrors('offer');
    $this->actingAs($f['leaderW1'])->post(route('trade.offers.withdraw', $offer->id))->assertRedirect();
    $this->actingAs($f['leaderSA1'])->get(route('trade.available.show', $offer->id))->assertNotFound();
    $this->actingAs($f['leaderW1'])->post(route('trade.offers.archive', $offer->id))->assertRedirect();
    expect($offer->fresh()->status->value)->toBe('archived');

    // the audit log recorded the key actions and cannot be edited
    expect(AuditLog::where('subject_id', $offer->id)->where('subject_type', 'Offer')->pluck('action')->all())->toBe(['offer.created', 'offer.published', 'offer.withdrawn', 'offer.archived']);
    $entry = AuditLog::query()->where('action', 'offer.published')->first();
    expect($entry->actor_id)->toBe($f['leaderW1']->id)->and($entry->organization_id)->toBe($f['a']->id)
        ->and(fn () => $entry->update(['action' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

test('strony modułu są tylko dla zalogowanych, a menu wie, co pokazać', function () {
    $f = tradeFixture();

    foreach (['trade.offers.index', 'trade.available.index', 'trade.orders.index', 'trade.partners.index', 'trade.permissions.index'] as $name) {
        $this->get(route($name))->assertRedirect(route('login'));
    }

    $this->actingAs($f['workerB1'])->get(route('trade.available.index'))->assertOk()->assertInertia(fn ($page) => $page->where('trade.allowed', true)->where('trade.offers', false)->where('trade.manage', false));
    $this->actingAs($f['leaderW1'])->get(route('trade.offers.index'))->assertOk()->assertInertia(fn ($page) => $page->where('trade.offers', true)->where('trade.manage', true));
    $this->actingAs($f['workerB1'])->get(route('trade.partners.index'))->assertForbidden();
    $this->actingAs($f['ownerB'])->get(route('trade.partners.index'))->assertOk()->assertInertia(fn ($page) => $page->where('can_invite', true)->where('is_owner', true));

    // the page of cooperation shows only the names of the other organization and the units of the scope
    $relationship = tradeCooperate($f);
    $this->actingAs($f['ownerB'])->get(route('trade.partners.index'))->assertInertia(fn ($page) => $page
        ->where('relationships.0.partner', 'Hurtownia A')->where('relationships.0.my_side', 'buyer')->has('relationships.0.my_units', 2)->has('relationships.0.partner_units', 1)
        ->where('relationships.0.can_manage', true));
    $this->actingAs($f['leaderB1'])->get(route('trade.partners.index'))->assertInertia(fn ($page) => $page->where('relationships.0.can_manage', false));
    expect($relationship->units()->count())->toBe(3);
});

test('S-11: pola chronione nie przechodzą przez formularz oferty (organizacja, status, wersja, autor)', function () {
    $f = tradeFixture();
    $other = Organization::create(['name' => 'Cudza']);

    $this->actingAs($f['leaderW1'])->post(route('trade.offers.store'), [
        'unit_ref' => tref($f['w1']), 'title' => 'Oferta', 'currency' => 'PLN', 'items' => tradeItems(),
        'organization_id' => $other->id, 'status' => 'published', 'current_version_id' => 999, 'created_by' => $f['ownerC']->id,
        'shared_internally' => true, 'number' => 'OF/HACK/1', 'published_at' => now()->toDateTimeString(),
    ])->assertRedirect();

    $offer = Offer::sole();
    expect($offer->organization_id)->toBe($f['a']->id)->and($offer->status->value)->toBe('draft')->and($offer->current_version_id)->toBeNull()
        ->and($offer->created_by)->toBe($f['leaderW1']->id)->and($offer->shared_internally)->toBeFalse()->and($offer->number)->not->toBe('OF/HACK/1')->and($offer->published_at)->toBeNull();

    // the same on an update: the status and the publication cannot be set from the form
    $this->actingAs($f['leaderW1'])->put(route('trade.offers.update', $offer->id), [
        'title' => 'Zmiana', 'currency' => 'PLN', 'items' => tradeItems(), 'status' => 'published', 'organization_id' => $other->id, 'shared_internally' => true,
    ])->assertRedirect();

    expect($offer->fresh()->status->value)->toBe('draft')->and($offer->fresh()->organization_id)->toBe($f['a']->id)->and($offer->fresh()->shared_internally)->toBeFalse();

    // an invitation: the organization and the role come from the server, not from the form
    $this->actingAs($f['ownerA'])->post(route('trade.partners.invite'), [
        'inviter_role' => 'supplier', 'invitee_email' => $f['ownerB']->email, 'units' => [tref($f['w1'])],
        'inviter_organization_id' => $f['c']->id, 'invitee_organization_id' => $f['c']->id, 'status' => 'accepted', 'invited_by' => $f['ownerC']->id,
    ])->assertRedirect();

    $invitation = TradeInvitation::sole();
    expect($invitation->inviter_organization_id)->toBe($f['a']->id)->and($invitation->invitee_organization_id)->toBe($f['b']->id)
        ->and($invitation->status->value)->toBe('pending')->and($invitation->invited_by)->toBe($f['ownerA']->id);
});

test('S-12: udostępnienie wielu jednostek naraz jest atomowe: jedna zła jednostka cofa całość', function () {
    $f = tradeFixture();
    tradeCooperate($f);
    $offer = tradeOffer($f['leaderW1'], $f['w1']);

    expect(fn () => app(OfferService::class)->share($f['leaderW1'], $offer, [tref($f['b1']), tref($f['c1'])]))->toThrow(Illuminate\Validation\ValidationException::class);
    expect(App\Models\OfferShare::count())->toBe(0);
    Notification::assertNotSentTo($f['leaderB1'], OfferSharedNotification::class);

    // the same through the form, with a unit that does not exist at all
    $this->actingAs($f['leaderW1'])->post(route('trade.offers.share', $offer->id), ['units' => [tref($f['b1']), 'shops:branches:999999']])->assertSessionHasErrors('units');
    expect(App\Models\OfferShare::count())->toBe(0);
});

test('upoważnić do zamawiania można tylko osobę z danej jednostki, a uprawnienie ginie po jej opuszczeniu', function () {
    $f = tradeReady();
    $grant = fn (User $by, Model $unit, User $user) => tradeGrant($by, $unit, $user);

    // a worker of another branch of the same organization, and a person of a different kind of unit, cannot be authorised for B1
    $workerB2 = User::factory()->create(['role' => UserRole::SHOP_WORKER, 'organization_id' => $f['b']->id, 'shop_headquarter_id' => $f['hqB']->id, 'shop_branch_id' => $f['b2']->id]);
    $grant($f['mgrB'], $f['b1'], $workerB2)->assertSessionHasErrors('user_id');
    $grant($f['ownerB'], $f['b1'], $f['leaderB2'])->assertSessionHasErrors('user_id');

    // the owner of organization A cannot authorise a warehouse worker for a shop of the same organization
    $grant($f['ownerA'], $f['sa1'], $f['workerW1'])->assertSessionHasErrors('user_id');
    $grant($f['ownerA'], $f['sa1'], $f['workerSA1'])->assertRedirect()->assertSessionHasNoErrors();
    expect(UnitOrderPermission::count())->toBe(1);

    // the people offered for a unit are its own people only
    $overview = app(App\Services\OrderPermissionService::class)->overview($f['ownerB']);
    $b1 = collect($overview['units'])->firstWhere('ref', tref($f['b1']));
    expect(collect($b1['candidates'])->pluck('id')->all())->toContain($f['workerB1']->id)->not->toContain($workerB2->id)->not->toContain($f['leaderB2']->id);

    // authorised for B1, then moved to B2: the old record no longer lets them order for B1 (nor for B2)
    $grant($f['leaderB1'], $f['b1'], $f['workerB1'])->assertRedirect();
    $this->actingAs($f['workerB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertRedirect();

    $f['workerB1']->forceFill(['shop_branch_id' => $f['b2']->id])->save();
    $this->actingAs($f['workerB1']->fresh())->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertForbidden();
    $this->actingAs($f['workerB1']->fresh())->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b2'])))->assertForbidden();
});

test('nadanie uprawnienia do zamawiania wymaga kodu z e-maila osoby nadającej', function () {
    $f = tradeReady();
    $request = fn (?User $by = null, ?Model $unit = null, ?User $user = null) => $this->actingAs($by ?? $f['leaderB1'])->post(route('trade.permissions.store'), ['unit' => tref($unit ?? $f['b1']), 'user_id' => ($user ?? $f['workerB1'])->id]);
    $code = function (User $by) {
        $code = null;
        Notification::assertSentTo($by, App\Notifications\OrderPermissionCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    };
    $wrong = fn (string $right) => $right === '000000' ? '111111' : '000000';

    // step one only sends the code: nothing is saved, and the worker still cannot order
    $request()->assertRedirect()->assertSessionHasNoErrors();
    expect(UnitOrderPermission::count())->toBe(0);
    $this->actingAs($f['workerB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertForbidden();

    // the page knows what waits for the code; only the person who asked sees it
    $this->actingAs($f['leaderB1'])->get(route('trade.permissions.index'))->assertInertia(fn ($page) => $page->where('pending.person', $f['workerB1']->name)->where('pending.unit_name', 'Sklep Rzeszów'));
    $this->actingAs($f['mgrB'])->get(route('trade.permissions.index'))->assertInertia(fn ($page) => $page->where('pending', null));

    // a wrong code and somebody else's code do nothing
    $right = $code($f['leaderB1']);
    $this->actingAs($f['leaderB1'])->post(route('trade.permissions.confirm'), ['code' => $wrong($right)])->assertSessionHasErrors('code');
    $this->actingAs($f['mgrB'])->post(route('trade.permissions.confirm'), ['code' => $right])->assertSessionHasErrors('code');
    $this->actingAs($f['workerB1'])->post(route('trade.permissions.confirm'), ['code' => $right])->assertSessionHasErrors('code');
    expect(UnitOrderPermission::count())->toBe(0);

    // the right code grants exactly what was requested, and is single use
    $this->actingAs($f['leaderB1'])->post(route('trade.permissions.confirm'), ['code' => $right])->assertRedirect()->assertSessionHasNoErrors();
    expect(UnitOrderPermission::sole())->user_id->toBe($f['workerB1']->id)->unit_ref->toBe(tref($f['b1']))->granted_by->toBe($f['leaderB1']->id);
    $this->actingAs($f['leaderB1'])->post(route('trade.permissions.confirm'), ['code' => $right])->assertSessionHasErrors('code');
    $this->actingAs($f['workerB1'])->post(route('trade.orders.store'), tradePayload($f['offer'], tref($f['b1'])))->assertRedirect();

    // five wrong codes drop the request, and the right one is then useless
    $other = User::factory()->create(['role' => UserRole::SHOP_WORKER, 'organization_id' => $f['b']->id, 'shop_headquarter_id' => $f['hqB']->id, 'shop_branch_id' => $f['b1']->id]);
    $request(user: $other)->assertRedirect();
    $right = $code($f['leaderB1']);

    foreach (range(1, 5) as $i) {
        $this->actingAs($f['leaderB1'])->post(route('trade.permissions.confirm'), ['code' => $wrong($right)])->assertSessionHasErrors('code');
    }

    $this->actingAs($f['leaderB1'])->post(route('trade.permissions.confirm'), ['code' => $right])->assertSessionHasErrors('code');
    expect(UnitOrderPermission::count())->toBe(1);

    // expired: after 16 minutes the code does not work
    $request(user: $other)->assertRedirect();
    $right = $code($f['leaderB1']);
    $this->travel(16)->minutes();
    $this->actingAs($f['leaderB1'])->post(route('trade.permissions.confirm'), ['code' => $right])->assertSessionHasErrors('code');
    expect(UnitOrderPermission::count())->toBe(1);
    $this->travelBack();

    // a new request replaces the old one (the old code no longer works), and cancelling drops it
    $request(user: $other)->assertRedirect();
    $first = $code($f['leaderB1']);
    $request(user: $other)->assertRedirect();
    $this->actingAs($f['leaderB1'])->post(route('trade.permissions.confirm'), ['code' => $first])->assertSessionHasErrors('code');

    $request(user: $other)->assertRedirect();
    $this->actingAs($f['leaderB1'])->delete(route('trade.permissions.cancel'))->assertRedirect();
    $this->actingAs($f['leaderB1'])->post(route('trade.permissions.confirm'), ['code' => $code($f['leaderB1'])])->assertSessionHasErrors('code');
    expect(UnitOrderPermission::count())->toBe(1);

    // the request itself is checked at once: a person of another unit is refused before any code is sent
    $request(user: $f['leaderB2'])->assertSessionHasErrors('user_id');
    $request(user: $f['leaderC1'])->assertSessionHasErrors('user_id');
});
