<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Notification;

const BRANCH_ORG_NIP = '5252344078';
const BRANCH_OTHER_NIP = '1234563218';

/**
 * Kierownik siedziby sklepu w organizacji z NIP-em.
 */
function shopManager(?Organization $organization = null, string $name = 'Sklep główny'): User
{
    $organization ??= Organization::create(['name' => 'Org', 'NIP' => BRANCH_ORG_NIP]);
    $headquarter = ShopHeadquarter::create(['organization_id' => $organization->id, 'name' => $name]);

    return User::factory()->create([
        'role' => UserRole::SHOP_OWNER,
        'organization_id' => $organization->id,
        'shop_headquarter_id' => $headquarter->id,
    ]);
}

/**
 * Kierownik placówki zaproszony przez $manager, po przyjęciu zaproszenia (jeszcze bez placówki).
 */
function invitedBranchLeader(User $manager): User
{
    Notification::fake();

    test()->actingAs($manager)
        ->post(route('shop.owner.branches.invitations.store'), ['email' => 'leader@example.com', 'phone' => '+48'.fake()->unique()->numerify('#########')])
        ->assertRedirect(route('shop.owner.branches.index'));

    $invitation = OrganizationInvitation::latest('id')->first();
    $code = null;
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($n) use (&$code) {
        $code = $n->verificationCode;

        return true;
    });

    $user = User::factory()->create(['email' => 'leader@example.com', 'role' => UserRole::Employee]);

    test()->actingAs($user)->post(route('organization-invitations.store', $invitation), ['code' => $code]);

    return $user->refresh();
}

test('zaproszenie placówki jest powiązane kluczem z organizacją i siedzibą kierownika', function () {
    $manager = shopManager();
    invitedBranchLeader($manager);

    $invitation = OrganizationInvitation::first();

    expect($invitation->role)->toBe(UserRole::SHOP_LEADER)
        ->and($invitation->organization_id)->toBe($manager->organization_id)
        ->and($invitation->shop_headquarter_id)->toBe($manager->shop_headquarter_id)
        ->and($invitation->shop_branch_id)->toBeNull();
});

test('po przyjęciu zaproszenia kierownik placówki trafia do formularza i zakłada placówkę w organizacji z zaproszenia', function () {
    $other = Organization::create(['name' => 'Inna']);
    $manager = shopManager();
    $leader = invitedBranchLeader($manager);

    expect($leader->role)->toBe(UserRole::SHOP_LEADER)
        ->and($leader->organization_id)->toBe($manager->organization_id)
        ->and($leader->shop_headquarter_id)->toBe($manager->shop_headquarter_id);

    $this->actingAs($leader)->get(route('shop.leader.dashboard'))->assertRedirect(route('shop.leader.setup'));
    $this->get(route('shop.leader.setup'))->assertOk();

    $this->post(route('shop.leader.setup.store'), [
        'name' => 'Placówka 1',
        'NIP' => BRANCH_OTHER_NIP,
        'organization_id' => $other->id,
        'shop_headquarter_id' => 999,
    ])->assertRedirect(route('shop.leader.dashboard'));

    $branch = ShopBranch::first();
    expect($branch->organization_id)->toBe($manager->organization_id)
        ->and($branch->shop_headquarter_id)->toBe($manager->shop_headquarter_id)
        ->and($branch->barcode)->not->toBeNull()
        ->and($leader->refresh()->shop_branch_id)->toBe($branch->id)
        ->and(OrganizationInvitation::first()->shop_branch_id)->toBe($branch->id);

    $this->get(route('shop.leader.dashboard'))->assertOk();
    $this->post(route('shop.leader.setup.store'), ['name' => 'Druga'])->assertForbidden();
});

test('NIP placówki może być NIP-em organizacji albo siedziby, a istniejącego innego nie można użyć', function () {
    $manager = shopManager();
    $leader = invitedBranchLeader($manager);

    $this->actingAs($leader)
        ->post(route('shop.leader.setup.store'), ['name' => 'Placówka', 'NIP' => BRANCH_ORG_NIP])
        ->assertSessionHasNoErrors();

    // NIP zajęty przez inną organizację
    Organization::create(['name' => 'Obca', 'NIP' => BRANCH_OTHER_NIP]);
    $second = invitedBranchLeaderFor($manager, 'second@example.com');

    $this->actingAs($second)
        ->post(route('shop.leader.setup.store'), ['name' => 'Placówka 2', 'NIP' => BRANCH_OTHER_NIP])
        ->assertSessionHasErrors('NIP');
    $this->post(route('shop.leader.setup.store'), ['name' => 'Placówka'])->assertSessionHasErrors('name');

    expect(ShopBranch::count())->toBe(1);
});

function invitedBranchLeaderFor(User $manager, string $email): User
{
    [$invitation] = OrganizationInvitation::issue(
        $manager->organization, $email, $manager, UserRole::SHOP_LEADER, $manager->shopHeadquarter,
    );

    $invitation->markAccepted();

    return User::factory()->create([
        'email' => $email,
        'role' => UserRole::SHOP_LEADER,
        'organization_id' => $manager->organization_id,
        'shop_headquarter_id' => $manager->shop_headquarter_id,
    ]);
}

test('kierownik nie może anulować zaproszenia placówki z innej siedziby ani organizacji', function () {
    $manager = shopManager();
    $foreignManager = shopManager(Organization::create(['name' => 'Obca']), 'Obcy sklep');
    invitedBranchLeader($foreignManager);
    $foreignInvitation = OrganizationInvitation::first();

    // anulowanie cudzego zaproszenia
    $this->actingAs($manager)
        ->delete(route('shop.owner.branches.invitations.destroy', $foreignInvitation->id))
        ->assertForbidden();

    // druga siedziba w tej samej organizacji
    $sibling = ShopHeadquarter::create(['organization_id' => $manager->organization_id, 'name' => 'Inny sklep']);
    $siblingManager = User::factory()->create([
        'role' => UserRole::SHOP_OWNER,
        'organization_id' => $manager->organization_id,
        'shop_headquarter_id' => $sibling->id,
    ]);
    [$own] = OrganizationInvitation::issue($manager->organization, 'a@example.com', $manager, UserRole::SHOP_LEADER, $manager->shopHeadquarter);

    $this->actingAs($siblingManager)
        ->delete(route('shop.owner.branches.invitations.destroy', $own->id))
        ->assertForbidden();

    $this->actingAs($manager)
        ->delete(route('shop.owner.branches.invitations.destroy', $own->id))
        ->assertRedirect();
    expect($own->fresh()->is_active)->toBeFalse();
});

test('lista placówek jest dostępna tylko dla kierownika siedziby sklepu z organizacją', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SHOP_OWNER]))
        ->get(route('shop.owner.branches.index'))
        ->assertForbidden();

    $manager = shopManager();

    $this->actingAs($manager)
        ->get(route('shop.owner.branches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('platform/shop/owner/branches/index')->where('headquarter.id', $manager->shop_headquarter_id));
});

test('konto z organizacją innej niż siedziba nie może zapraszać placówek', function () {
    $manager = shopManager();
    $manager->forceFill(['organization_id' => Organization::create(['name' => 'Inna'])->id])->save();

    Notification::fake();
    $this->actingAs($manager)
        ->post(route('shop.owner.branches.invitations.store'), ['email' => 'a@example.com', 'phone' => '+48'.fake()->unique()->numerify('#########')])
        ->assertForbidden();

    expect(OrganizationInvitation::count())->toBe(0);
});
