<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Tworzy zaproszenie i zwraca je razem z jawnym kodem.
 *
 * @return array{0: OrganizationInvitation, 1: string}
 */
function invitationFor(string $email, ?Organization $organization = null): array
{
    $organization ??= Organization::create(['name' => 'Org']);

    return OrganizationInvitation::issue($organization, $email, User::factory()->create(['role' => UserRole::Admin]));
}

/**
 * Zaproszenie do założenia nowej organizacji (bez istniejącej organizacji).
 *
 * @return array{0: OrganizationInvitation, 1: string}
 */
function newOrganizationInvitationFor(string $email): array
{
    return OrganizationInvitation::issue(null, $email, User::factory()->create(['role' => UserRole::Admin]));
}

test('admin wysyła zaproszenie z kodem w mailu, a w bazie jest tylko hash kodu', function () {
    Notification::fake();
    $org = Organization::create(['name' => 'Org']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->post(route('platform.admin.organizations.invitations.store', $org), ['email' => 'Owner@Example.com'])
        ->assertRedirect(route('platform.admin.organizations.index'));

    $invitation = OrganizationInvitation::first();

    expect($invitation->email)->toBe('owner@example.com')
        ->and($invitation->role)->toBe(UserRole::ORGANIZATION_OWNER)
        ->and($invitation->code)->toHaveLength(64);

    Notification::assertSentOnDemand(
        OrganizationInvitationNotification::class,
        function ($notification) use ($invitation) {
            return preg_match('/^\d{6}$/', $notification->verificationCode) === 1
                && $invitation->checkCode($notification->verificationCode)
                && $invitation->verification_code !== $notification->verificationCode;
        },
    );
});

test('tylko administrator może wysyłać zaproszenia', function () {
    $org = Organization::create(['name' => 'Org']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Manager]))
        ->post(route('platform.admin.organizations.invitations.store', $org), ['email' => 'a@example.com'])
        ->assertForbidden();

    expect(OrganizationInvitation::count())->toBe(0);
});

test('nowe zaproszenie na ten sam adres unieważnia poprzednie', function () {
    Notification::fake();
    $org = Organization::create(['name' => 'Org']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    foreach (range(1, 2) as $_) {
        $this->actingAs($admin)->post(
            route('platform.admin.organizations.invitations.store', $org),
            ['email' => 'owner@example.com'],
        );
    }

    // poprzednie zaproszenie zostaje w bazie jako wycofane przez administratora
    expect(OrganizationInvitation::count())->toBe(2)
        ->and(OrganizationInvitation::where('is_active', true)->count())->toBe(1);

    $revoked = OrganizationInvitation::where('is_active', false)->first();

    expect($revoked->revoked_at)->not->toBeNull()
        ->and($revoked->revoked_by)->toBe($admin->id);
});

test('poprawny kod i zgodny e-mail nadają rolę ORGANIZATION_OWNER oraz organizację', function () {
    $org = Organization::create(['name' => 'Org']);
    [$invitation, $code] = invitationFor('owner@example.com', $org);
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($user)
        ->post(route('organization-invitations.store', $invitation), ['code' => $code])
        ->assertRedirect(route('platform.organization.dashboard'));

    $user->refresh();

    expect($user->role)->toBe(UserRole::ORGANIZATION_OWNER)
        ->and($user->organization_id)->toBe($org->id)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    $this->get(route('platform.organization.dashboard'))->assertOk();
});

test('konto z innym adresem e-mail nie może przyjąć zaproszenia', function () {
    [$invitation, $code] = invitationFor('owner@example.com');
    $stranger = User::factory()->create(['email' => 'stranger@example.com']);

    $this->actingAs($stranger)
        ->post(route('organization-invitations.store', $invitation), ['code' => $code])
        ->assertSessionHasErrors('code');

    expect($stranger->fresh()->role)->toBe(UserRole::Employee)
        ->and($stranger->fresh()->organization_id)->toBeNull();
});

test('błędny kod zwiększa licznik prób, a po pięciu zaproszenie się blokuje', function () {
    [$invitation, $code] = invitationFor('owner@example.com');
    $user = User::factory()->create(['email' => 'owner@example.com']);

    foreach (range(1, OrganizationInvitation::MAX_ATTEMPTS) as $_) {
        $this->actingAs($user)
            ->post(route('organization-invitations.store', $invitation), ['code' => '000000'])
            ->assertSessionHasErrors('code');
    }

    expect($invitation->fresh()->isLocked())->toBeTrue();

    // nawet poprawny kod nie pomaga po zablokowaniu
    $this->actingAs($user)
        ->post(route('organization-invitations.store', $invitation), ['code' => $code])
        ->assertSessionHasErrors('code');

    expect($user->fresh()->role)->toBe(UserRole::Employee);
});

test('wygasłego zaproszenia nie można przyjąć', function () {
    [$invitation, $code] = invitationFor('owner@example.com');
    $invitation->update(['expires_at' => now()->subMinute()]);
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($user)
        ->post(route('organization-invitations.store', $invitation), ['code' => $code])
        ->assertSessionHasErrors('code');

    expect($user->fresh()->role)->toBe(UserRole::Employee);
});

test('zaproszenie nie nadpisze roli administratora ani roli w sklepie', function (UserRole $role) {
    [$invitation, $code] = invitationFor('owner@example.com');
    $user = User::factory()->create(['email' => 'owner@example.com', 'role' => $role]);

    $this->actingAs($user)
        ->post(route('organization-invitations.store', $invitation), ['code' => $code])
        ->assertSessionHasErrors('code');

    expect($user->fresh()->role)->toBe($role);
})->with([UserRole::Admin, UserRole::SHOP_OWNER]);

test('gość bez konta z linku trafia na rejestrację z polem na klucz', function () {
    [$invitation] = invitationFor('owner@example.com');

    $this->get(route('organization-invitations.show', $invitation))
        ->assertRedirect(route('register', ['organization_invitation' => $invitation->code]));

    $this->get(route('register', ['organization_invitation' => $invitation->code]))
        ->assertInertia(fn ($page) => $page
            ->component('auth/register')
            ->where('organizationInvitation.email', 'owner@example.com')
            ->where('organizationInvitation.code', $invitation->code)
            ->has('organizationInvitation.resendUrl'));
});

test('gość z istniejącym kontem z linku trafia na logowanie', function () {
    [$invitation] = invitationFor('owner@example.com');
    User::factory()->create(['email' => 'owner@example.com']);

    $this->get(route('organization-invitations.show', $invitation))
        ->assertRedirect(route('login'));
});

/**
 * @return array<string, string>
 */
function registrationPayload(OrganizationInvitation $invitation, string $code, array $override = []): array
{
    return array_merge([
        'name' => 'Jan Właściciel',
        'email' => $invitation->email,
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
        'privacy' => '1',
        'organization_invitation' => $invitation->code,
        'invitation_key' => $code,
    ], $override);
}

test('rejestracja z kluczem tworzy konto z rolą ORGANIZATION_OWNER i loguje', function () {
    [$invitation, $code] = newOrganizationInvitationFor('owner@example.com');

    $this->post(route('register.store'), registrationPayload($invitation, $code, ['email' => 'Owner@Example.com']))
        ->assertRedirect(route('platform.organization.dashboard'));

    $user = User::where('email', 'owner@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(UserRole::ORGANIZATION_OWNER)
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->barcode)->toStartWith('2001')
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
    $this->get(route('platform.organization.dashboard'))->assertRedirect(route('platform.organization.setup'));
});

test('rejestracja z kluczem wymaga poprawnego klucza i adresu z zaproszenia', function () {
    [$invitation, $code] = newOrganizationInvitationFor('owner@example.com');

    $this->post(route('register.store'), registrationPayload($invitation, $code, ['email' => 'inny@example.com']))
        ->assertSessionHasErrors('email');

    $this->post(route('register.store'), registrationPayload($invitation, '000000'))
        ->assertSessionHasErrors('invitation_key');

    $this->post(route('register.store'), registrationPayload($invitation, '', ['invitation_key' => '']))
        ->assertSessionHasErrors('invitation_key');

    expect(User::where('email', 'owner@example.com')->exists())->toBeFalse()
        ->and($invitation->fresh()->attempts)->toBe(1);
});

test('zablokowane i wygasłe zaproszenie nie pozwala się zarejestrować jako właściciel', function () {
    [$locked, $lockedCode] = newOrganizationInvitationFor('locked@example.com');
    $locked->forceFill(['attempts' => OrganizationInvitation::MAX_ATTEMPTS])->save();

    [$expired, $expiredCode] = newOrganizationInvitationFor('expired@example.com');
    $expired->update(['expires_at' => now()->subMinute()]);

    $this->post(route('register.store'), registrationPayload($locked, $lockedCode))
        ->assertSessionHasErrors('invitation_key');
    $this->post(route('register.store'), registrationPayload($expired, $expiredCode))
        ->assertSessionHasErrors('invitation_key');

    expect(User::whereIn('email', ['locked@example.com', 'expired@example.com'])->count())->toBe(0);
});

test('nowy klucz trafia na adres z zaproszenia, stary przestaje działać, a próby się zerują', function () {
    Notification::fake();
    [$invitation, $oldCode] = newOrganizationInvitationFor('owner@example.com');
    $invitation->forceFill(['attempts' => 3, 'updated_at' => now()->subMinutes(5)])->saveQuietly();

    $this->post(route('organization-invitations.resend', $invitation))->assertSessionHasNoErrors();

    $newCode = null;
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($notification, $channels, $notifiable) use (&$newCode) {
        $newCode = $notification->verificationCode;

        return $notifiable->routes['mail'] === 'owner@example.com';
    });

    expect($invitation->fresh()->attempts)->toBe(0)
        ->and($invitation->fresh()->checkCode($newCode))->toBeTrue()
        ->and($newCode === $oldCode || $invitation->fresh()->checkCode($oldCode))->toBe($newCode === $oldCode);

    $this->post(route('register.store'), registrationPayload($invitation, $newCode))
        ->assertRedirect(route('platform.organization.dashboard'));
});

test('nowy klucz nie jest wysyłany częściej niż raz na minutę ani dla przyjętego zaproszenia', function () {
    Notification::fake();
    [$invitation] = newOrganizationInvitationFor('owner@example.com');

    // świeżo utworzone zaproszenie: klucz został wysłany przed chwilą
    $this->post(route('organization-invitations.resend', $invitation))->assertSessionHasErrors('resend');

    $invitation->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
    $invitation->update(['accepted_at' => now()]);

    $this->post(route('organization-invitations.resend', $invitation))->assertSessionHasErrors('resend');

    Notification::assertNothingSent();
});

test('strona organizacji przekazuje barcode organizacji', function () {
    $org = Organization::create(['name' => 'Org']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get(route('platform.admin.organizations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/admin/organizations/index')
            ->where('organizations.0.barcode', $org->fresh()->barcode));
});

test('mail zaproszenia zawiera nazwę platformy, organizację i klucz', function () {
    $org = Organization::create(['name' => 'Moja Organizacja']);
    $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Jan Admin']);
    [$invitation, $code] = OrganizationInvitation::issue($org, 'owner@example.com', $admin);

    $html = (string) (new OrganizationInvitationNotification($invitation, $code))
        ->toMail(new stdClass)
        ->render();

    expect($html)->toContain('RadegastTradeB2BPlatform')
        ->toContain('Jan Admin')
        ->toContain('Moja Organizacja')
        ->toContain($code)
        ->toContain(route('organization-invitations.show', $invitation));
});

test('przyjęcie zaproszenia potwierdza adres e-mail nowego konta', function () {
    [$invitation, $code] = invitationFor('owner@example.com');
    $user = User::factory()->unverified()->create(['email' => 'owner@example.com']);

    $this->actingAs($user)
        ->post(route('organization-invitations.store', $invitation), ['code' => $code])
        ->assertRedirect(route('platform.organization.dashboard'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('admin zaprasza nową organizację samym e-mailem, bez tworzenia jej ręcznie', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get(route('platform.admin.organizations.create'))->assertOk();

    $this->actingAs($admin)
        ->post(route('platform.admin.organizations.invitations.store-new'), ['email' => 'Nowy@Example.com'])
        ->assertRedirect(route('platform.admin.organizations.index'));

    $invitation = OrganizationInvitation::first();

    expect(Organization::count())->toBe(0)
        ->and($invitation->organization_id)->toBeNull()
        ->and($invitation->email)->toBe('nowy@example.com');

    Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
});

test('po przyjęciu zaproszenia właściciel sam podaje nazwę organizacji, a e-mail jest ustalony', function () {
    [$invitation, $code] = newOrganizationInvitationFor('owner@example.com');
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($user)
        ->post(route('organization-invitations.store', $invitation), ['code' => $code])
        ->assertRedirect(route('platform.organization.dashboard'));

    expect($user->fresh()->role)->toBe(UserRole::ORGANIZATION_OWNER)
        ->and($user->fresh()->organization_id)->toBeNull();

    // bez organizacji panel odsyła do formularza danych; e-mail jest ustalony, nazwę podaje właściciel
    $this->get(route('platform.organization.dashboard'))->assertRedirect(route('platform.organization.setup'));
    $this->get(route('platform.organization.setup'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/organization/setup')
            ->where('email', 'owner@example.com')
            ->missing('organizationName'));

    // nazwa pochodzi od właściciela, a próba zmiany e-maila w żądaniu jest ignorowana
    $this->post(route('platform.organization.setup.store'), [
        'name' => '  Moja Firma  ',
        'email' => 'inny@example.com',
        'NIP' => '1234563218',
        'REGON' => '',
        'country' => 'PL',
    ])->assertRedirect(route('platform.organization.dashboard'));

    $org = Organization::where('NIP', '1234563218')->first();

    expect($org->name)->toBe('Moja Firma')
        ->and($org->email)->toBe('owner@example.com')
        ->and($org->barcode)->toStartWith('2002')
        ->and($org->REGON)->toBeNull()
        ->and($user->fresh()->organization_id)->toBe($org->id)
        ->and($invitation->fresh()->organization_id)->toBe($org->id);

    $this->get(route('platform.organization.dashboard'))->assertOk();
});

test('dane organizacji są walidowane: nazwa wymagana, NIP z sumą kontrolną i unikalny', function () {
    Organization::create(['name' => 'Istniejąca', 'NIP' => '1234563218']);
    $owner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER]);

    // zła długość i zła suma kontrolna
    $this->actingAs($owner)
        ->post(route('platform.organization.setup.store'), ['name' => 'X', 'NIP' => '123'])
        ->assertSessionHasErrors('NIP');
    $this->actingAs($owner)
        ->post(route('platform.organization.setup.store'), ['name' => 'X', 'NIP' => '1234567890'])
        ->assertSessionHasErrors('NIP');

    // NIP istniejącej organizacji
    $this->actingAs($owner)
        ->post(route('platform.organization.setup.store'), ['name' => 'Druga', 'NIP' => '1234563218'])
        ->assertSessionHasErrors('NIP');

    // nazwa organizacji jest wymagana
    $this->actingAs($owner)
        ->post(route('platform.organization.setup.store'), ['name' => '', 'NIP' => '5260250995'])
        ->assertSessionHasErrors('name');

    expect(Organization::count())->toBe(1);
});

test('właściciel z organizacją nie założy drugiej', function () {
    $org = Organization::create(['name' => 'Pierwsza']);
    $owner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $org->id]);

    $this->actingAs($owner)
        ->post(route('platform.organization.setup.store'), ['name' => 'Druga'])
        ->assertForbidden();

    $this->actingAs($owner)
        ->get(route('platform.organization.setup'))
        ->assertRedirect(route('platform.organization.dashboard'));

    expect(Organization::count())->toBe(1);
});

test('konto przypisane do organizacji nie przyjmie zaproszenia do nowej', function () {
    $org = Organization::create(['name' => 'Pierwsza']);
    [$invitation, $code] = newOrganizationInvitationFor('owner@example.com');
    $user = User::factory()->create([
        'email' => 'owner@example.com',
        'role' => UserRole::ORGANIZATION_OWNER,
        'organization_id' => $org->id,
    ]);

    $this->actingAs($user)
        ->post(route('organization-invitations.store', $invitation), ['code' => $code])
        ->assertSessionHasErrors('code');

    expect($user->fresh()->organization_id)->toBe($org->id);
});

test('tylko administrator może zapraszać nowe organizacje', function () {
    Notification::fake();

    $this->actingAs(User::factory()->create(['role' => UserRole::Manager]))
        ->post(route('platform.admin.organizations.invitations.store-new'), ['email' => 'a@example.com'])
        ->assertForbidden();

    expect(OrganizationInvitation::count())->toBe(0);
});

test('rejestracja jest możliwa tylko z zaproszenia: strona bez zaproszenia odsyła do logowania', function () {
    $this->get(route('register'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Rejestracja jest możliwa tylko z zaproszenia.');

    // nieaktualny lub zmyślony token też nie otwiera rejestracji
    $this->get(route('register', ['organization_invitation' => 'zmyslony-token']))
        ->assertRedirect(route('login'));
});

test('nie można zarejestrować się samodzielnie, bez zaproszenia', function () {
    $this->post(route('register.store'), [
        'name' => 'Jan',
        'email' => 'ktos@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
        'privacy' => '1',
    ])->assertSessionHasErrors(['email' => 'Rejestracja jest możliwa tylko z zaproszenia.']);

    expect(User::where('email', 'ktos@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

test('samo pole klucza bez tokenu zaproszenia nie pozwala się zarejestrować', function () {
    [, $code] = newOrganizationInvitationFor('owner@example.com');

    $this->post(route('register.store'), [
        'name' => 'Jan',
        'email' => 'owner@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
        'privacy' => '1',
        'invitation_key' => $code,
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'owner@example.com')->exists())->toBeFalse();
});

test('wygasłe zaproszenie do zespołu nie pozwala się zarejestrować', function () {
    $owner = User::factory()->create();
    $team = App\Models\Team::factory()->create();
    $invitation = App\Models\TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
        'expires_at' => now()->subDay(),
    ]);

    $this->post(route('register.store'), [
        'name' => 'Jan',
        'email' => 'invited@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
        'privacy' => '1',
        'team_invitation' => $invitation->code,
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'invited@example.com')->exists())->toBeFalse();
});

test('rejestracja wymaga akceptacji regulaminu i RODO i zapisuje datę zgód', function () {
    [$invitation, $code] = newOrganizationInvitationFor('owner@example.com');

    $payload = registrationPayload($invitation, $code);

    // bez zgód konto nie powstaje, a klucz nie jest zużywany
    $this->post(route('register.store'), array_diff_key($payload, ['terms' => 1, 'privacy' => 1]))
        ->assertSessionHasErrors(['terms', 'privacy']);

    expect(User::where('email', 'owner@example.com')->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();

    $this->freezeTime();

    $this->post(route('register.store'), $payload)
        ->assertRedirect(route('platform.organization.dashboard'));

    $user = User::where('email', 'owner@example.com')->first();

    expect($user->terms_accepted_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($user->privacy_accepted_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

test('każda ze zgód osobno jest wymagana', function (string $missing) {
    [$invitation, $code] = newOrganizationInvitationFor('owner@example.com');

    $payload = registrationPayload($invitation, $code);
    unset($payload[$missing]);

    $this->post(route('register.store'), $payload)->assertSessionHasErrors($missing);

    expect(User::where('email', 'owner@example.com')->exists())->toBeFalse();
})->with(['terms', 'privacy']);

test('regulamin i RODO są dostępne bez logowania', function () {
    $this->get(route('legal.terms'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('legal/terms'));

    $this->get(route('legal.privacy'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('legal/privacy'));
});

test('administrator wycofuje zaproszenie organizacji: zostaje w bazie z datą i autorem wycofania', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    [$invitation, $code] = newOrganizationInvitationFor('owner@example.com');

    $this->actingAs($admin)
        ->delete(route('platform.admin.organizations.invitations.destroy', $invitation))
        ->assertRedirect(route('platform.admin.organizations.index'));

    $invitation->refresh();

    expect($invitation->is_active)->toBeFalse()
        ->and($invitation->revoked_at)->not->toBeNull()
        ->and($invitation->revoked_by)->toBe($admin->id)
        ->and($invitation->revoker->is($admin))->toBeTrue()
        ->and($invitation->isPending())->toBeFalse();

    // zniknęło z listy, a link i rejestracja przestają działać
    $this->actingAs($admin)
        ->get(route('platform.admin.organizations.index'))
        ->assertInertia(fn ($page) => $page->has('newOrganizationInvitations', 0));

    auth()->logout();

    $this->post(route('register.store'), registrationPayload($invitation, $code))
        ->assertSessionHasErrors('invitation_key');

    expect(User::where('email', 'owner@example.com')->exists())->toBeFalse();
});

test('przyjęte zaproszenie organizacji jest nieaktywne, ale nie jest wycofane', function () {
    [$invitation, $code] = newOrganizationInvitationFor('owner@example.com');

    $this->post(route('register.store'), registrationPayload($invitation, $code));

    $invitation->refresh();

    expect($invitation->accepted_at)->not->toBeNull()
        ->and($invitation->is_active)->toBeFalse()
        ->and($invitation->revoked_at)->toBeNull();
});

test('logowanie zapisuje czas ostatniego logowania i strefę czasową miejsca logowania', function () {
    $user = User::factory()->create(['password' => 'qwer1234']);

    expect($user->last_login_at)->toBeNull();

    $this->freezeTime();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'qwer1234',
        'timezone' => 'Europe/Warsaw',
    ]);

    $user->refresh();

    expect($user->last_login_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($user->last_login_timezone)->toBe('Europe/Warsaw')
        ->and($user->last_login_local->getTimezone()->getName())->toBe('Europe/Warsaw')
        // UTC + 1 lub +2 w zależności od czasu letniego
        ->and($user->last_login_local->utcOffset())->toBeIn([60, 120]);
});

test('nieprawidłowa strefa czasowa jest ignorowana, a czas lokalny to wtedy czas aplikacji', function () {
    $user = User::factory()->create(['password' => 'qwer1234']);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'qwer1234',
        'timezone' => 'Nie/Strefa',
    ]);

    $user->refresh();

    expect($user->last_login_at)->not->toBeNull()
        ->and($user->last_login_timezone)->toBeNull()
        ->and($user->last_login_local->getTimezone()->getName())->toBe(config('app.timezone'));
});

test('rejestracja z zaproszenia też zapisuje ostatnie logowanie', function () {
    [$invitation, $code] = newOrganizationInvitationFor('owner@example.com');

    $this->post(route('register.store'), registrationPayload($invitation, $code, ['timezone' => 'America/New_York']));

    $user = User::where('email', 'owner@example.com')->first();

    expect($user->last_login_at)->not->toBeNull()
        ->and($user->last_login_timezone)->toBe('America/New_York');
});

test('panel organizacji pokazuje dashboard ze statystykami i dane organizacji', function () {
    $org = Organization::create(['name' => 'Moja Organizacja', 'NIP' => '1234563218', 'city' => 'Jasło']);
    $org->shopHeadquarters()->create(['name' => 'Sklep 1']);
    $org->shopHeadquarters()->create(['name' => 'Sklep 2']);
    $org->warehouseHeadquarters()->create(['name' => 'Hurtownia 1']);

    $owner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $org->id]);
    User::factory()->create(['organization_id' => $org->id]);
    // użytkownik z innej organizacji nie jest widoczny
    User::factory()->create(['organization_id' => Organization::create(['name' => 'Obca'])->id]);

    $this->actingAs($owner)
        ->get(route('platform.organization.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/organization/dashboard/index')
            ->where('organization.name', 'Moja Organizacja')
            ->where('organization.barcode', $org->fresh()->barcode)
            ->where('stats.shopHeadquarters', 2)
            ->where('stats.warehouseHeadquarters', 1)
            ->where('stats.people', 2)
            ->has('people', 2));

    $this->get(route('platform.organization.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/organization/index')
            ->where('organization.NIP', '1234563218')
            ->where('organization.city', 'Jasło'));
});

test('panel organizacji jest zamknięty dla innych ról', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SHOP_OWNER]))
        ->get(route('platform.organization.dashboard'))
        ->assertForbidden();
});

test('administrator zaprasza tylko e-mailem: nazwy organizacji nie podaje', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->post(route('platform.admin.organizations.invitations.store-new'), ['email' => 'nowy@example.com'])
        ->assertSessionHasNoErrors();

    expect(OrganizationInvitation::count())->toBe(1)
        ->and(Illuminate\Support\Facades\Schema::hasColumn('organization_invitations', 'organization_name'))->toBeFalse();
});

test('mail zaproszenia nowej organizacji informuje, że nazwę poda zaproszony', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    [$invitation, $code] = OrganizationInvitation::issue(null, 'owner@example.com', $admin);

    $html = (string) (new OrganizationInvitationNotification($invitation, $code))->toMail(new stdClass)->render();

    expect($html)->toContain('nowej organizacji')->toContain('nazwę i dane podasz');
});

test('panel właściciela organizacji leży w grupie platform i ma widoki sklepów oraz hurtowni', function () {
    expect(UserRole::ORGANIZATION_OWNER->group())->toBe('platform')
        ->and(UserRole::ORGANIZATION_OWNER->basePath())->toBe('/platform/organization')
        ->and(UserRole::ORGANIZATION_OWNER->dashboardRoute())->toBe('platform.organization.dashboard');

    $org = Organization::create(['name' => 'Org']);
    $owner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $org->id]);

    $this->actingAs($owner)
        ->get(route('platform.organization.headquarters.index', 'shops'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('platform/organization/shops/index'));

    $this->get(route('platform.organization.headquarters.index', 'warehouses'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('platform/organization/warehouses/index'));
});

test('widoki sklepów i hurtowni organizacji są zamknięte dla innych ról', function (string $type) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Manager]))
        ->get(route('platform.organization.headquarters.index', $type))
        ->assertForbidden();
})->with(['shops', 'warehouses']);
