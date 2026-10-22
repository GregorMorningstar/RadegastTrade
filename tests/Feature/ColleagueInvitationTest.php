<?php

use App\Enums\UserRole;
use App\Models\ColleagueInvitation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\TradeInvitation;
use App\Models\TradeRelationship;
use App\Models\TradeRelationshipUnit;
use App\Models\UnitOrderPermission;
use App\Models\User;
use App\Models\WarehouseHeadquarter;
use App\Notifications\ColleagueCodeNotification;
use App\Notifications\ColleagueInvitationNotification;
use App\Notifications\TradeInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Inviting a person of the same organization to units: the invited person accepts with a code from their e-mail,
| a leader needs the consent (also with a code) of the manager of their headquarters, otherwise of the owner
|--------------------------------------------------------------------------
*/

beforeEach(fn () => Notification::fake());

/**
 * Owner; shop headquarters A with its manager, branches A1 (leader, worker) and A2; a branch B without a headquarters
 * (with a leader); a warehouse headquarters with its manager; a foreign organization's owner.
 *
 * @return array<string, mixed>
 */
function colleagueFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);
    $hq = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep A']);
    $a1 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hq->id, 'name' => 'A1']);
    $a2 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hq->id, 'name' => 'A2']);
    $b = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => null, 'name' => 'B']);
    $wh = WarehouseHeadquarter::create(['organization_id' => $org->id, 'name' => 'Hurtownia']);

    $person = fn (UserRole $role, string $name, array $units = [], ?int $orgId = null) => User::factory()->create(['role' => $role, 'name' => $name, 'organization_id' => $orgId ?? $org->id, ...$units]);

    return [
        'hq' => $hq, 'a1' => $a1, 'a2' => $a2, 'b' => $b, 'wh' => $wh,
        'owner' => $person(UserRole::ORGANIZATION_OWNER, 'Właściciel'),
        'mA' => $person(UserRole::SHOP_OWNER, 'Kierownik A', ['shop_headquarter_id' => $hq->id]),
        'lA1' => $person(UserRole::SHOP_LEADER, 'Lider A1', ['shop_headquarter_id' => $hq->id, 'shop_branch_id' => $a1->id]),
        'wA1' => $person(UserRole::SHOP_WORKER, 'Pracownik A1', ['shop_headquarter_id' => $hq->id, 'shop_branch_id' => $a1->id]),
        'lA2' => $person(UserRole::SHOP_LEADER, 'Lider A2', ['shop_headquarter_id' => $hq->id, 'shop_branch_id' => $a2->id]),
        'lB' => $person(UserRole::SHOP_LEADER, 'Lider B', ['shop_branch_id' => $b->id]),
        'mW' => $person(UserRole::WAREHOUSE_OWNER, 'Kierownik hurtowni', ['warehouse_headquarter_id' => $wh->id]),
        'foreign' => $person(UserRole::ORGANIZATION_OWNER, 'Obcy', [], $other->id),
    ];
}

function codeSentTo(User $user, string $purpose): string
{
    $code = null;
    Notification::assertSentTo($user, ColleagueCodeNotification::class, function (ColleagueCodeNotification $notification) use (&$code, $purpose) {
        $code = $notification->code;

        return $notification->purpose === $purpose;
    });

    return $code;
}

function invite(User $by, User $who, array $units): TestResponse
{
    return test()->actingAs($by)->post(route('trade.colleagues.store'), ['user_id' => $who->id, 'units' => $units]);
}

test('zapraszać może tylko właściciel i kierownik siedziby, tylko do swoich jednostek i tego samego rodzaju', function () {
    $f = colleagueFixture();

    // a worker and a leader do not invite
    foreach (['wA1', 'lA1', 'lB'] as $who) {
        invite($f[$who], $f['lA2'], ['shops:branches:'.$f['a2']->id])->assertForbidden();
    }

    // the manager invites to his headquarters' units, not to a unit of another headquarters or the owner's own ones
    invite($f['mA'], $f['wA1'], ['shops:branches:'.$f['a2']->id])->assertSessionHasNoErrors();
    expect(ColleagueInvitation::count())->toBe(1);
    Notification::assertSentTo($f['wA1'], ColleagueInvitationNotification::class);
    invite($f['mA'], $f['lA2'], ['shops:branches:'.$f['b']->id])->assertSessionHasErrors('units');
    invite($f['mA'], $f['lA2'], ['shops:headquarters:'.$f['hq']->id, 'shops:branches:999'])->assertSessionHasErrors('units');

    // a warehouse person does not order for a shop; a person of another organization or oneself cannot be invited
    invite($f['owner'], $f['mW'], ['shops:branches:'.$f['a1']->id])->assertSessionHasErrors('units');
    invite($f['owner'], $f['foreign'], ['shops:branches:'.$f['a1']->id])->assertSessionHasErrors('user_id');
    invite($f['owner'], $f['owner'], ['shops:branches:'.$f['a1']->id])->assertSessionHasErrors('user_id');
    invite($f['owner'], $f['wA1'], [])->assertSessionHasErrors('units');

    // one waiting invitation per person
    invite($f['owner'], $f['wA1'], ['shops:branches:'.$f['a1']->id])->assertSessionHasErrors('user_id');
});

test('zaproszony przyjmuje kodem z własnego e-maila i dopiero wtedy dostaje dostęp', function () {
    $f = colleagueFixture();
    $units = ['shops:branches:'.$f['a1']->id, 'shops:branches:'.$f['a2']->id];
    invite($f['owner'], $f['wA1'], $units)->assertSessionHasNoErrors();
    $invitation = ColleagueInvitation::sole();
    expect($invitation->consent_required)->toBeFalse();
    expect(UnitOrderPermission::count())->toBe(0);

    // nobody else answers it; the invitation is not even visible to them
    $this->actingAs($f['lA2'])->post(route('trade.colleagues.accept-code', $invitation->id))->assertNotFound();
    $this->actingAs($f['owner'])->post(route('trade.colleagues.accept', $invitation->id), ['code' => '123456'])->assertNotFound();

    $this->actingAs($f['wA1'])->post(route('trade.colleagues.accept-code', $invitation->id))->assertSessionHasNoErrors();
    $code = codeSentTo($f['wA1'], 'accept');
    Notification::assertNotSentTo($f['owner'], ColleagueCodeNotification::class);

    $wrong = $code === '000000' ? '111111' : '000000';
    $this->post(route('trade.colleagues.accept', $invitation->id), ['code' => $wrong])->assertSessionHasErrors('code');
    $this->post(route('trade.colleagues.accept', $invitation->id), ['code' => 'abc'])->assertSessionHasErrors('code');
    expect(UnitOrderPermission::count())->toBe(0);

    $this->post(route('trade.colleagues.accept', $invitation->id), ['code' => $code])->assertSessionHasNoErrors();
    expect(UnitOrderPermission::where('user_id', $f['wA1']->id)->whereNull('revoked_at')->pluck('unit_ref')->sort()->values()->all())->toBe(collect($units)->sort()->values()->all());
    expect($invitation->fresh()->status)->toBe('accepted');

    // single use
    $this->post(route('trade.colleagues.accept', $invitation->id), ['code' => $code])->assertSessionHasErrors('invitation');
});

test('kod: pięć błędnych prób go unieważnia, wygasa po 15 minutach, odrzucenie niczego nie nadaje', function () {
    $f = colleagueFixture();
    invite($f['owner'], $f['wA1'], ['shops:branches:'.$f['a1']->id]);
    $invitation = ColleagueInvitation::sole();
    $this->actingAs($f['wA1']);

    $this->post(route('trade.colleagues.accept-code', $invitation->id));
    $code = codeSentTo($f['wA1'], 'accept');
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $i) {
        $this->post(route('trade.colleagues.accept', $invitation->id), ['code' => $wrong])->assertSessionHasErrors('code');
    }

    $this->post(route('trade.colleagues.accept', $invitation->id), ['code' => $code])->assertSessionHasErrors('code');
    expect(UnitOrderPermission::count())->toBe(0);

    $this->post(route('trade.colleagues.accept-code', $invitation->id));
    $fresh = codeSentTo($f['wA1'], 'accept');
    $this->travel(16)->minutes();
    $this->post(route('trade.colleagues.accept', $invitation->id), ['code' => $fresh])->assertSessionHasErrors('code');
    $this->travelBack();

    $this->post(route('trade.colleagues.reject', $invitation->id));
    expect($invitation->fresh()->status)->toBe('rejected')->and(UnitOrderPermission::count())->toBe(0);
});

test('lider potrzebuje zgody kierownika swojej siedziby, potwierdzonej jego kodem', function () {
    $f = colleagueFixture();
    invite($f['owner'], $f['lA1'], ['shops:branches:'.$f['a1']->id, 'shops:branches:'.$f['a2']->id]);
    $invitation = ColleagueInvitation::sole();
    expect($invitation->consent_required)->toBeTrue();

    // without the consent there is no code for him and nothing to accept
    $this->actingAs($f['lA1'])->post(route('trade.colleagues.accept-code', $invitation->id))->assertSessionHasErrors('invitation');
    $this->post(route('trade.colleagues.accept', $invitation->id), ['code' => '123456'])->assertSessionHasErrors('invitation');

    // he asks: the manager of the headquarters is told (no code yet), the owner is not
    $this->post(route('trade.colleagues.consent-request', $invitation->id))->assertSessionHasNoErrors();
    Notification::assertSentTo($f['mA'], ColleagueInvitationNotification::class, fn ($n) => $n->kind === 'consent');
    Notification::assertNotSentTo($f['owner'], ColleagueInvitationNotification::class, fn ($n) => $n->kind === 'consent');

    // the manager sees it, the owner, the leader himself and a foreign leader cannot consent
    $this->actingAs($f['mA'])->get(route('trade.partners.index'))->assertInertia(fn ($page) => $page->has('colleagues.consents', 1));
    $this->actingAs($f['owner'])->post(route('trade.colleagues.consent-code', $invitation->id))->assertForbidden();
    $this->actingAs($f['lA1'])->post(route('trade.colleagues.consent-code', $invitation->id))->assertForbidden();
    $this->actingAs($f['lA2'])->post(route('trade.colleagues.consent-code', $invitation->id))->assertForbidden();

    // the manager confirms with the code from his own e-mail
    $this->actingAs($f['mA'])->post(route('trade.colleagues.consent-code', $invitation->id))->assertSessionHasNoErrors();
    $consent = codeSentTo($f['mA'], 'consent');
    $this->post(route('trade.colleagues.consent', $invitation->id), ['code' => $consent === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
    $this->post(route('trade.colleagues.consent', $invitation->id), ['code' => $consent])->assertSessionHasNoErrors();
    expect($invitation->fresh()->consented_by)->toBe($f['mA']->id);
    expect(UnitOrderPermission::count())->toBe(0);

    // now the leader accepts with his own code
    $this->actingAs($f['lA1'])->post(route('trade.colleagues.accept-code', $invitation->id))->assertSessionHasNoErrors();
    $this->post(route('trade.colleagues.accept', $invitation->id), ['code' => codeSentTo($f['lA1'], 'accept')])->assertSessionHasNoErrors();
    expect(UnitOrderPermission::where('user_id', $f['lA1']->id)->count())->toBe(2);
});

test('lider placówki bez siedziby prosi o zgodę właściciela', function () {
    $f = colleagueFixture();
    invite($f['owner'], $f['lB'], ['shops:branches:'.$f['b']->id]);
    $invitation = ColleagueInvitation::sole();

    $this->actingAs($f['lB'])->post(route('trade.colleagues.consent-request', $invitation->id))->assertSessionHasNoErrors();
    Notification::assertSentTo($f['owner'], ColleagueInvitationNotification::class, fn ($n) => $n->kind === 'consent');
    Notification::assertNotSentTo($f['mA'], ColleagueInvitationNotification::class, fn ($n) => $n->kind === 'consent');

    $this->actingAs($f['mA'])->post(route('trade.colleagues.consent-code', $invitation->id))->assertForbidden();
    $this->actingAs($f['owner'])->post(route('trade.colleagues.consent-code', $invitation->id))->assertSessionHasNoErrors();
    $this->post(route('trade.colleagues.consent', $invitation->id), ['code' => codeSentTo($f['owner'], 'consent')])->assertSessionHasNoErrors();

    // the one who invited cannot be bypassed on the other side: a worker needs no consent at all
    expect(ColleagueInvitation::sole()->consented_at)->not->toBeNull();
    $this->actingAs($f['lB'])->post(route('trade.colleagues.consent-request', $invitation->id))->assertSessionHasErrors('invitation');
});

test('zaproszenie nieaktualne: zapraszający stracił uprawnienia lub zaproszenie wygasło; cofnąć może właściciel albo autor', function () {
    $f = colleagueFixture();
    invite($f['mA'], $f['wA1'], ['shops:branches:'.$f['a1']->id]);
    $invitation = ColleagueInvitation::sole();

    // the manager is deactivated before the answer: nothing is granted on his authority
    $f['mA']->forceFill(['is_active' => false])->save();
    $this->actingAs($f['wA1'])->post(route('trade.colleagues.accept-code', $invitation->id));
    $this->post(route('trade.colleagues.accept', $invitation->id), ['code' => codeSentTo($f['wA1'], 'accept')])->assertSessionHasErrors('invitation');
    expect(UnitOrderPermission::count())->toBe(0);

    // an expired one cannot be answered
    $invitation->update(['expires_at' => now()->subMinute()]);
    $this->post(route('trade.colleagues.accept-code', $invitation->id))->assertSessionHasErrors('invitation');

    // cancelling: another manager no, the owner yes
    $f['mA']->forceFill(['is_active' => true])->save();
    $invitation->update(['expires_at' => now()->addDay()]);
    $this->actingAs($f['mW'])->delete(route('trade.colleagues.cancel', $invitation->id))->assertForbidden();
    $this->actingAs($f['owner'])->delete(route('trade.colleagues.cancel', $invitation->id));
    expect($invitation->fresh()->status)->toBe('cancelled');
});

test('zaproszenie do współpracy wybiera się z listy jednostek innych organizacji, a adresat to ich kierownicy', function () {
    $f = colleagueFixture();
    $other = Organization::create(['name' => 'Hurt Obcy']);
    $hq = WarehouseHeadquarter::create(['organization_id' => $other->id, 'name' => 'Magazyn Z']);
    $shop = ShopHeadquarter::create(['organization_id' => $other->id, 'name' => 'Sklep Z']);
    $manager = User::factory()->create(['role' => UserRole::WAREHOUSE_OWNER, 'organization_id' => $other->id, 'warehouse_headquarter_id' => $hq->id]);
    $wh = WarehouseHeadquarter::create(['organization_id' => $f['owner']->organization_id, 'name' => 'Moja hurtownia']);

    // the list holds units of other organizations only, by name
    $this->actingAs($f['owner'])->get(route('trade.partners.index'))->assertInertia(fn ($page) => $page
        ->where('targets', fn ($targets) => collect($targets)->pluck('name')->contains('Magazyn Z') && ! collect($targets)->pluck('name')->contains('A1')));

    // a shop only buys: it cannot be invited to sell, a warehouse can
    $this->post(route('trade.partners.invite'), ['inviter_role' => 'buyer', 'target_unit' => 'shops:headquarters:'.$shop->id, 'units' => ['warehouses:headquarters:'.$wh->id]]);
    expect(TradeInvitation::count())->toBe(0);
    $this->post(route('trade.partners.invite'), ['inviter_role' => 'buyer', 'target_unit' => 'warehouses:headquarters:'.$hq->id, 'units' => ['warehouses:headquarters:'.$wh->id]])->assertSessionHasNoErrors();
    expect(TradeInvitation::sole()->invitee_organization_id)->toBe($other->id);
    Notification::assertSentTo($manager, TradeInvitationNotification::class);

    // own unit or an unknown one: nothing is sent, the answer is the same
    $this->post(route('trade.partners.invite'), ['inviter_role' => 'supplier', 'target_unit' => 'warehouses:headquarters:'.$wh->id, 'units' => ['warehouses:headquarters:'.$wh->id]])->assertSessionHasNoErrors();
    expect(TradeInvitation::count())->toBe(1);
});

test('organizacja, której wysłano zaproszenie, znika z listy do zaproszenia do czasu odpowiedzi', function () {
    $f = colleagueFixture();
    $other = Organization::create(['name' => 'Hurt Obcy']);
    $hq = WarehouseHeadquarter::create(['organization_id' => $other->id, 'name' => 'Magazyn Z']);
    User::factory()->create(['role' => UserRole::WAREHOUSE_OWNER, 'organization_id' => $other->id, 'warehouse_headquarter_id' => $hq->id]);
    $mine = WarehouseHeadquarter::create(['organization_id' => $f['owner']->organization_id, 'name' => 'Moja hurtownia']);
    $names = fn () => collect($this->actingAs($f['owner'])->get(route('trade.partners.index'))->viewData('page')['props']['targets'])->reject(fn ($t) => in_array('buyer', $t['taken']))->pluck('name');

    expect($names())->toContain('Magazyn Z');
    $this->post(route('trade.partners.invite'), ['inviter_role' => 'buyer', 'target_unit' => 'warehouses:headquarters:'.$hq->id, 'units' => ['warehouses:headquarters:'.$mine->id]])->assertSessionHasNoErrors();
    expect($names())->not->toContain('Magazyn Z');

    // after it is withdrawn, the organization can be invited again
    $this->delete(route('trade.partners.invitations.cancel', TradeInvitation::sole()->id));
    expect($names())->toContain('Magazyn Z');
});

test('wiele jednostek naraz: każda dostaje osobne zaproszenie, zaproszona jednostka znika z listy, reszta zostaje', function () {
    $f = colleagueFixture();
    $other = Organization::create(['name' => 'Hurt Obcy']);
    $h1 = WarehouseHeadquarter::create(['organization_id' => $other->id, 'name' => 'Magazyn 1']);
    $h2 = WarehouseHeadquarter::create(['organization_id' => $other->id, 'name' => 'Magazyn 2']);
    $third = Organization::create(['name' => 'Trzecia']);
    $h3 = WarehouseHeadquarter::create(['organization_id' => $third->id, 'name' => 'Magazyn 3']);
    $m1 = User::factory()->create(['role' => UserRole::WAREHOUSE_OWNER, 'organization_id' => $other->id, 'warehouse_headquarter_id' => $h1->id]);
    $m2 = User::factory()->create(['role' => UserRole::WAREHOUSE_OWNER, 'organization_id' => $other->id, 'warehouse_headquarter_id' => $h2->id]);
    $m3 = User::factory()->create(['role' => UserRole::WAREHOUSE_OWNER, 'organization_id' => $third->id, 'warehouse_headquarter_id' => $h3->id]);
    $mine = WarehouseHeadquarter::create(['organization_id' => $f['owner']->organization_id, 'name' => 'Moja hurtownia']);
    $send = fn (array $targets) => $this->actingAs($f['owner'])->post(route('trade.partners.invite'), ['inviter_role' => 'buyer', 'target_units' => $targets, 'units' => ['warehouses:headquarters:'.$mine->id]]);
    $refs = fn (...$hqs) => array_map(fn ($hq) => 'warehouses:headquarters:'.$hq->id, $hqs);

    $send($refs($h1, $h2, $h3))->assertSessionHasNoErrors();
    expect(TradeInvitation::count())->toBe(3);
    expect(TradeInvitation::pluck('invitee_unit_ref')->sort()->values()->all())->toBe(collect($refs($h1, $h2, $h3))->sort()->values()->all());
    foreach ([$m1, $m2, $m3] as $manager) {
        Notification::assertSentTo($manager, TradeInvitationNotification::class);
    }

    // the invited units are gone from the list; sending again does not double the invitations
    $names = collect($this->get(route('trade.partners.index'))->viewData('page')['props']['targets'])->reject(fn ($t) => in_array('buyer', $t['taken']))->pluck('name');
    expect($names)->not->toContain('Magazyn 1');
    $send($refs($h1, $h2))->assertSessionHasNoErrors();
    expect(TradeInvitation::count())->toBe(3);

    // a second unit of the same organization can be invited later with its own invitation
    $h4 = WarehouseHeadquarter::create(['organization_id' => $other->id, 'name' => 'Magazyn 4']);
    User::factory()->create(['role' => UserRole::WAREHOUSE_OWNER, 'organization_id' => $other->id, 'warehouse_headquarter_id' => $h4->id]);
    $send($refs($h4))->assertSessionHasNoErrors();
    expect(TradeInvitation::count())->toBe(4);
});

test('otrzymane zaproszenia widać w skrzynce (czat i dashboard) i można je przyjąć w panelu po numerze', function () {
    $f = colleagueFixture();
    $other = Organization::create(['name' => 'Hurt Obcy']);
    $hq = WarehouseHeadquarter::create(['organization_id' => $other->id, 'name' => 'Magazyn Z']);
    $manager = User::factory()->create(['role' => UserRole::WAREHOUSE_OWNER, 'organization_id' => $other->id, 'warehouse_headquarter_id' => $hq->id]);
    $mine = WarehouseHeadquarter::create(['organization_id' => $f['owner']->organization_id, 'name' => 'Moja hurtownia']);

    $this->actingAs($f['owner'])->post(route('trade.partners.invite'), ['inviter_role' => 'supplier', 'target_units' => ['warehouses:headquarters:'.$hq->id], 'units' => ['warehouses:headquarters:'.$mine->id]])->assertSessionHasNoErrors();
    $invitation = TradeInvitation::sole();
    $inbox = fn (User $user) => $this->actingAs($user)->get(route('chat.index'))->viewData('page')['props']['invitations'];

    // the manager of the invited unit sees it in the shared props (the chat page and every dashboard); the inviter and others do not
    expect($inbox($manager))->toHaveCount(1)->and($inbox($manager)[0]['href'])->toBe('/trade/invitations/id/'.$invitation->id);
    expect($inbox($f['owner']))->toBe([])->and($inbox($f['foreign']))->toBe([]);

    // it opens inside the panel; somebody else's organization gets 404
    $this->actingAs($manager)->get('/trade/invitations/id/'.$invitation->id)->assertOk();
    $this->actingAs($f['foreign'])->get('/trade/invitations/id/'.$invitation->id)->assertNotFound();
    $this->actingAs($f['foreign'])->post('/trade/invitations/id/'.$invitation->id.'/accept', ['all_current' => true])->assertNotFound();

    $this->actingAs($manager)->post('/trade/invitations/id/'.$invitation->id.'/accept', ['units' => ['warehouses:headquarters:'.$hq->id]])->assertSessionHasNoErrors();
    expect($invitation->fresh()->status->value)->toBe('accepted')->and($inbox($manager))->toBe([]);
});

test('zaproszenie do jednostek i prośba o zgodę trafiają do skrzynki właściwych osób', function () {
    $f = colleagueFixture();
    invite($f['owner'], $f['lA1'], ['shops:branches:'.$f['a1']->id]);
    $inbox = fn (User $user) => collect($this->actingAs($user)->get(route('chat.index'))->viewData('page')['props']['invitations'])->pluck('kind')->all();

    expect($inbox($f['lA1']))->toBe(['colleague'])->and($inbox($f['mA']))->toBe([]);
    $this->actingAs($f['lA1'])->post(route('trade.colleagues.consent-request', ColleagueInvitation::sole()->id));
    expect($inbox($f['mA']))->toBe(['consent'])->and($inbox($f['owner']))->toBe([]);
});

/** The organization "Hurt" (owner, a warehouse) invites the units of the fixture's shop headquarters A (the HQ and both branches), each separately. */
function inviteHeadquartersAndBranches(array $f): array
{
    $hurt = Organization::create(['name' => 'Hurt']);
    $wh = WarehouseHeadquarter::create(['organization_id' => $hurt->id, 'name' => 'Magazyn Hurt']);
    $owner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $hurt->id]);

    test()->actingAs($owner)->post(route('trade.partners.invite'), [
        'inviter_role' => 'supplier',
        'target_units' => ['shops:headquarters:'.$f['hq']->id, 'shops:branches:'.$f['a1']->id, 'shops:branches:'.$f['a2']->id],
        'units' => ['warehouses:headquarters:'.$wh->id],
    ])->assertSessionHasNoErrors();

    return [TradeInvitation::where('invitee_unit_ref', 'shops:headquarters:'.$f['hq']->id)->sole(), $owner];
}

test('zaakceptowanie przez kierownika siedziby dodaje zaproszone jednostki podległe, z wiadomością na czacie i alertem', function () {
    $f = colleagueFixture();
    [$invitation] = inviteHeadquartersAndBranches($f);
    expect(TradeInvitation::count())->toBe(3);

    $this->actingAs($f['mA'])->post('/trade/invitations/id/'.$invitation->id.'/accept', ['units' => ['shops:headquarters:'.$f['hq']->id]])->assertSessionHasNoErrors();

    // the invitations of both branches are accepted with it and the branches are in the scope of the cooperation
    expect(TradeInvitation::where('status', 'accepted')->count())->toBe(3);
    $rows = TradeRelationshipUnit::where('organization_id', $f['owner']->organization_id)->get();
    expect($rows->pluck('unit_ref')->sort()->values()->all())->toBe(collect(['shops:headquarters:'.$f['hq']->id, 'shops:branches:'.$f['a1']->id, 'shops:branches:'.$f['a2']->id])->sort()->values()->all());
    expect($rows->whereNotNull('auto_added_by')->pluck('auto_added_by')->unique()->all())->toBe([$f['mA']->id]);
    expect($rows->whereNotNull('auto_added_by'))->toHaveCount(2);

    // a message in the chat from the manager to each leader
    expect(Message::where('sender_id', $f['mA']->id)->pluck('recipient_id')->sort()->values()->all())->toBe(collect([$f['lA1']->id, $f['lA2']->id])->sort()->values()->all());

    // an alert for each leader (not for the manager who accepted); a leader closes only his own
    $alerts = fn (User $user) => collect($this->actingAs($user)->get(route('chat.index'))->viewData('page')['props']['invitations'])->where('kind', 'alert')->values();
    expect($alerts($f['lA1']))->toHaveCount(1)->and($alerts($f['lA2']))->toHaveCount(1)->and($alerts($f['mA']))->toHaveCount(0);
    $dismiss = $alerts($f['lA1'])[0]['dismiss'];
    $this->actingAs($f['lA2'])->post($dismiss)->assertForbidden();
    $this->actingAs($f['lA1'])->post($dismiss)->assertSessionHasNoErrors();
    expect($alerts($f['lA1']))->toHaveCount(0)->and($alerts($f['lA2']))->toHaveCount(1);
});

test('zaakceptowanie przez lidera nie dociąga innych jednostek, a niezaproszone podległe nie dołączają', function () {
    $f = colleagueFixture();
    [$invitation] = inviteHeadquartersAndBranches($f);
    $branch = TradeInvitation::where('invitee_unit_ref', 'shops:branches:'.$f['a1']->id)->sole();

    $this->actingAs($f['lA1'])->post('/trade/invitations/id/'.$branch->id.'/accept', ['units' => ['shops:branches:'.$f['a1']->id]])->assertSessionHasNoErrors();
    expect(TradeInvitation::where('status', 'accepted')->count())->toBe(1);
    expect(Message::count())->toBe(0);

    // the owner of the organization accepting for the headquarters: the branch that already joined is not announced again
    $this->actingAs($f['mA'])->post('/trade/invitations/id/'.$invitation->id.'/accept', ['units' => ['shops:headquarters:'.$f['hq']->id]])->assertSessionHasNoErrors();
    expect(Message::where('recipient_id', $f['lA1']->id)->count())->toBe(0)->and(Message::where('recipient_id', $f['lA2']->id)->count())->toBe(1);
});

test('zaproszenie, które pokrywa już istniejąca współpraca, nie jest pokazywane (skrzynka ani lista wysłanych)', function () {
    $f = colleagueFixture();
    [$invitation, $hurtOwner] = inviteHeadquartersAndBranches($f);
    $inbox = fn (User $user) => collect($this->actingAs($user)->get(route('chat.index'))->viewData('page')['props']['invitations'])->where('kind', 'trade')->count();
    $sent = fn () => count($this->actingAs($hurtOwner)->get(route('trade.partners.index'))->viewData('page')['props']['sent']);

    // the manager of the headquarters sees his invitation and those of his branches, a branch leader his own
    expect($inbox($f['mA']))->toBe(3)->and($inbox($f['lA1']))->toBe(1)->and($sent())->toBe(3);

    // the headquarters accepts: the whole line is a cooperation now, so the invitation is no longer shown anywhere
    $this->actingAs($f['mA'])->post('/trade/invitations/id/'.$invitation->id.'/accept', ['units' => ['shops:headquarters:'.$f['hq']->id]]);
    expect($inbox($f['mA']))->toBe(0)->and($inbox($f['lA1']))->toBe(0);

    // a new invitation to a unit that is not in the scope yet is shown, one already covered by the cooperation is not
    $b = ShopBranch::create(['organization_id' => $f['owner']->organization_id, 'shop_headquarter_id' => $f['hq']->id, 'name' => 'A3']);
    $leaderA3 = User::factory()->create(['role' => UserRole::SHOP_LEADER, 'organization_id' => $f['owner']->organization_id, 'shop_headquarter_id' => $f['hq']->id, 'shop_branch_id' => $b->id]);
    $wh = WarehouseHeadquarter::where('name', 'Magazyn Hurt')->sole();
    $this->actingAs($hurtOwner)->post(route('trade.partners.invite'), ['inviter_role' => 'supplier', 'target_units' => ['shops:branches:'.$b->id], 'units' => ['warehouses:headquarters:'.$wh->id]]);
    expect($inbox($leaderA3))->toBe(1);
});

test('lista do zaproszenia oznacza jednostki z wysłanym zaproszeniem lub nawiązaną współpracą (osobno dla każdej roli)', function () {
    $f = colleagueFixture();
    [$invitation, $hurtOwner] = inviteHeadquartersAndBranches($f);
    $taken = fn () => collect($this->actingAs($hurtOwner)->get(route('trade.partners.index'))->viewData('page')['props']['targets'])->keyBy('ref')->map(fn ($t) => $t['taken']);
    $hq = 'shops:headquarters:'.$f['hq']->id;
    $a3 = ShopBranch::create(['organization_id' => $f['owner']->organization_id, 'shop_headquarter_id' => $f['hq']->id, 'name' => 'A3']);

    // invited as buyers of this organization: taken for the role "supplier" only
    expect($taken()[$hq])->toBe(['supplier'])->and($taken()['shops:branches:'.$a3->id])->toBe([]);

    // after the cooperation starts, the units in its scope are taken too, those outside of it are not
    $this->actingAs($f['mA'])->post('/trade/invitations/id/'.$invitation->id.'/accept', ['units' => [$hq]]);
    expect($taken()[$hq])->toBe(['supplier'])->and($taken()['shops:branches:'.$f['a1']->id])->toBe(['supplier'])->and($taken()['shops:branches:'.$a3->id])->toBe([]);
});

test('po akceptacji współpracy osoby zarządzające jednostkami partnera są w czacie, a po jej zakończeniu znikają', function () {
    $f = colleagueFixture();
    [$invitation, $hurtOwner] = inviteHeadquartersAndBranches($f);
    $contacts = fn (User $user) => collect($this->actingAs($user)->getJson(route('chat.contacts'))->json('contacts'))->keyBy('id');

    // before the answer: nobody of the other organization is a contact, and they cannot write
    expect($contacts($hurtOwner)->has($f['mA']->id))->toBeFalse();
    $this->actingAs($hurtOwner)->postJson(route('chat.send', $f['mA']->id), ['body' => 'Cześć'])->assertNotFound();

    $this->actingAs($f['mA'])->post('/trade/invitations/id/'.$invitation->id.'/accept', ['units' => ['shops:headquarters:'.$f['hq']->id]]);

    // the owner who invited sees the people who manage the units that joined, with their organization; and the other way round
    $mine = $contacts($hurtOwner);
    expect($mine->has($f['mA']->id))->toBeTrue()->and($mine->has($f['lA1']->id))->toBeTrue()->and($mine->has($f['lA2']->id))->toBeTrue();
    expect($mine[$f['mA']->id]['organization'])->toBe('Org');
    expect($contacts($f['mA'])->has($hurtOwner->id))->toBeTrue()->and($contacts($f['mA'])[$hurtOwner->id]['organization'])->toBe('Hurt');

    // they can talk, nobody else of the partner organization is reachable (a worker does not manage a unit)
    $this->actingAs($hurtOwner)->postJson(route('chat.send', $f['mA']->id), ['body' => 'Witam we współpracy'])->assertCreated();
    $this->actingAs($f['mA'])->getJson(route('chat.messages', $hurtOwner->id))->assertOk()->assertJsonCount(1, 'messages');
    $this->actingAs($hurtOwner)->postJson(route('chat.send', $f['wA1']->id), ['body' => 'x'])->assertNotFound();
    $this->actingAs($f['wA1'])->postJson(route('chat.send', $hurtOwner->id), ['body' => 'x'])->assertNotFound();
    $this->actingAs($f['foreign'])->postJson(route('chat.send', $f['mA']->id), ['body' => 'x'])->assertNotFound();

    // the cooperation ends: the contact is gone on both sides
    $relationship = TradeRelationship::sole();
    $this->actingAs($hurtOwner)->post(route('trade.partners.end', $relationship->id));
    expect($contacts($hurtOwner)->has($f['mA']->id))->toBeFalse()->and($contacts($f['mA'])->has($hurtOwner->id))->toBeFalse();
    $this->actingAs($hurtOwner)->postJson(route('chat.send', $f['mA']->id), ['body' => 'x'])->assertNotFound();
});

test('kontakty czatu mówią, kim jest osoba (rodzaj i poziom), do filtrów i ikon', function () {
    $f = colleagueFixture();
    $contacts = collect($this->actingAs($f['owner'])->getJson(route('chat.contacts'))->json('contacts'))->keyBy('id');

    expect($contacts[$f['mA']->id])->toMatchArray(['kind' => 'shop', 'level' => 'headquarters']);
    expect($contacts[$f['lA1']->id])->toMatchArray(['kind' => 'shop', 'level' => 'branch']);
    expect($contacts[$f['wA1']->id])->toMatchArray(['kind' => 'shop', 'level' => 'worker']);
    expect($contacts[$f['mW']->id])->toMatchArray(['kind' => 'warehouse', 'level' => 'headquarters']);

    $fromShop = collect($this->actingAs($f['wA1'])->getJson(route('chat.contacts'))->json('contacts'))->keyBy('id');
    expect($fromShop[$f['owner']->id])->toMatchArray(['kind' => 'owner', 'level' => 'organization']);
});
