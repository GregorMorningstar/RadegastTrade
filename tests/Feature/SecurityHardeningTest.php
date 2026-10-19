<?php

use App\Enums\TeamRole;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ShopHeadquarter;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TradeInvitation;
use App\Models\TradeRelationship;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Notifications\TradeInvitationNotification;
use App\Services\TradeRelationshipService;
use App\Support\MailText;
use App\Support\UnitRef;
use Database\Seeders\DakarSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Regression tests of the security audit (see docs of each test for the finding it pins down)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('login-ip:127.0.0.1');
});

// ---------------------------------------------------------------------------------------------------------------------
// S-01: a default-role account (Employee) could list the people of every organization
// ---------------------------------------------------------------------------------------------------------------------

test('S-01: konto z domyślną rolą nie podgląda osób z organizacji, a administrator i manager tak', function () {
    $org = Organization::create(['name' => 'Org']);
    $hq = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Siedziba']);
    User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $org->id, 'shop_headquarter_id' => $hq->id, 'name' => 'Kierownik']);

    // the role every person gets who registers through a team invitation (CreateNewUser)
    $stranger = User::factory()->create(['role' => UserRole::Employee]);

    $this->actingAs($stranger)->getJson(route('platform.organizations.members', $org))->assertForbidden();
    $this->actingAs($stranger)->getJson(route('platform.headquarters.tree', 'shops'))->assertForbidden();
    $this->actingAs($stranger)->getJson(route('platform.headquarters.tree', 'warehouses'))->assertForbidden();

    // an account of another organization, a worker, a guest: no either
    $worker = User::factory()->create(['role' => UserRole::SHOP_WORKER, 'organization_id' => Organization::create(['name' => 'Inna'])->id]);
    $this->actingAs($worker)->getJson(route('platform.organizations.members', $org))->assertForbidden();

    foreach ([UserRole::Admin, UserRole::Manager] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))->getJson(route('platform.organizations.members', $org))->assertOk()->assertJsonPath('headquarters.data.0.people.0.name', 'Kierownik');
    }

    auth()->logout();
    $this->getJson(route('platform.organizations.members', $org))->assertUnauthorized();
});

// ---------------------------------------------------------------------------------------------------------------------
// S-02: the password reset form told whether an address has an account
// ---------------------------------------------------------------------------------------------------------------------

test('S-02: reset hasła odpowiada tak samo dla znanego i nieznanego adresu', function () {
    $user = User::factory()->create();

    $known = $this->post('/forgot-password', ['email' => $user->email]);
    $unknown = $this->post('/forgot-password', ['email' => 'nikt@example.com']);

    expect($unknown->status())->toBe($known->status())
        ->and($unknown->getSession()->get('status'))->toBe($known->getSession()->get('status'))->not->toBeNull()
        ->and($unknown->getSession()->has('errors'))->toBeFalse()
        ->and($known->getSession()->has('errors'))->toBeFalse();

    // the link is sent only to the real address
    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);

    $this->postJson('/forgot-password', ['email' => 'nikt@example.com'])->assertOk()->assertJsonPath('message', trans('passwords.sent'));
});

test('S-02: prośby o reset hasła są limitowane na adres i na skrzynkę', function () {
    foreach (range(1, 3) as $i) {
        $this->post('/forgot-password', ['email' => 'ofiara@example.com'])->assertSessionHasNoErrors();
    }

    // the same mailbox: the fourth request in ten minutes is refused, whoever sends it
    $this->post('/forgot-password', ['email' => 'ofiara@example.com'])->assertStatus(429);
    $this->post('/forgot-password', ['email' => 'Ofiara@Example.com'])->assertStatus(429);

    // one machine asking for many mailboxes
    RateLimiter::clear('auth-throttle:password.email:email:'.hash('sha256', 'ofiara@example.com'));
    $codes = collect(range(1, 8))->map(fn (int $i) => $this->post('/forgot-password', ['email' => "ktos{$i}@example.com"])->status());
    expect($codes->contains(429))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------------------------------
// S-03: logging in with many different accounts from one address was not limited
// ---------------------------------------------------------------------------------------------------------------------

test('S-03: logowanie jest limitowane także na sam adres, nie tylko na konto', function () {
    $codes = collect(range(1, 40))->map(fn (int $i) => $this->post('/login', ['email' => "u{$i}@example.com", 'password' => 'zle-haslo'])->status());

    expect($codes->take(30)->every(fn (int $code) => $code !== 429))->toBeTrue()
        ->and($codes->slice(30)->every(fn (int $code) => $code === 429))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------------------------------
// S-04: a changed password left the other sessions of the account open
// ---------------------------------------------------------------------------------------------------------------------

test('S-04: zmiana hasła kończy pozostałe sesje, a bieżąca działa dalej', function () {
    $user = User::factory()->create(['password' => 'Stare-haslo-123!']);
    $oldHash = $user->getAuthPassword();

    // another browser: its session holds the hash of the password it logged in with
    $other = $this->actingAs($user)->withSession(['password_hash_web' => $oldHash]);
    $other->get(route('profile.edit'))->assertOk();

    // this browser changes the password in the settings
    $this->actingAs($user)->withSession(['password_hash_web' => $oldHash])
        ->put(route('user-password.update'), ['current_password' => 'Stare-haslo-123!', 'password' => 'Nowe-haslo-456!!', 'password_confirmation' => 'Nowe-haslo-456!!'])
        ->assertSessionHasNoErrors();

    expect(Hash::check('Nowe-haslo-456!!', $user->fresh()->password))->toBeTrue();

    // a session that still carries the old hash is no longer a login
    $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])->get(route('profile.edit'))->assertRedirect(route('login'));

    // the session that changed the password carries on (it got the new hash)
    $this->actingAs($user->fresh())->withSession(['password_hash_web' => $user->fresh()->getAuthPassword()])->get(route('profile.edit'))->assertOk();
});

// ---------------------------------------------------------------------------------------------------------------------
// S-05: team invitations could be taken over by changing the e-mail address of an account
// ---------------------------------------------------------------------------------------------------------------------

test('S-05: zaproszenie do zespołu przyjmie tylko osoba z potwierdzonym adresem e-mail', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $invite = fn (string $email) => TeamInvitation::create(['team_id' => $team->id, 'email' => $email, 'role' => TeamRole::Member, 'invited_by' => $owner->id, 'expires_at' => now()->addDay()]);

    // an account that took the address in its profile (the address becomes unverified) does not get the invitation
    $attacker = User::factory()->unverified()->create(['email' => 'ofiara@example.com']);
    $invitation = $invite('ofiara@example.com');

    $this->actingAs($attacker)->post(route('invitations.accept', $invitation))->assertRedirect(route('verification.notice'));
    expect($team->memberships()->where('user_id', $attacker->id)->exists())->toBeFalse()->and($invitation->fresh()->accepted_at)->toBeNull();

    $this->actingAs($attacker)->delete(route('invitations.decline', $invitation))->assertRedirect(route('verification.notice'));
    expect($invitation->fresh()->is_active)->toBeTrue();

    // the owner of the mailbox (verified) accepts
    $owner2 = User::factory()->create(['email' => 'inna@example.com']);
    $this->actingAs($owner2)->post(route('invitations.accept', $invite('inna@example.com')))->assertRedirect();
    expect($team->memberships()->where('user_id', $owner2->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------------------------------
// S-06: the 6-digit key of an invitation could be guessed in bursts, and "resend" reset the counter without limit
// ---------------------------------------------------------------------------------------------------------------------

function securityInvitation(string $email = 'nowy@example.com'): array
{
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    return OrganizationInvitation::issue(null, $email, $admin);
}

test('S-06: klucz zaproszenia: liczenie prób pod blokadą, po limicie nawet dobry klucz jest odrzucany', function () {
    [$invitation, $code] = securityInvitation();
    $wrong = $code === '000000' ? '111111' : '000000';

    expect(array_map(fn () => $invitation->attemptCode($wrong), range(1, 4)))->toBe(['wrong', 'wrong', 'wrong', 'wrong'])
        ->and($invitation->attemptCode($wrong))->toBe('locked');

    // the counter stops at the limit, however many more guesses come, and the right key does not open a locked invitation
    foreach (range(1, 10) as $i) {
        expect($invitation->attemptCode($i % 2 ? $wrong : $code))->toBe('locked');
    }

    expect($invitation->fresh()->attempts)->toBe(OrganizationInvitation::MAX_ATTEMPTS)->and($invitation->fresh()->isPending())->toBeFalse();

    // an accepted, withdrawn or expired invitation is "unavailable", not a place to guess
    [$second, $secondCode] = securityInvitation('drugi@example.com');
    $second->markAccepted();
    expect($second->attemptCode($secondCode))->toBe('unavailable');

    [$third, $thirdCode] = securityInvitation('trzeci@example.com');
    $third->forceFill(['expires_at' => now()->subMinute()])->save();
    expect($third->attemptCode($thirdCode))->toBe('unavailable');

    // the right key works while there are attempts left
    [$fourth, $fourthCode] = securityInvitation('czwarty@example.com');
    $fourth->attemptCode($wrong);
    expect($fourth->attemptCode($fourthCode))->toBe('ok')->and($fourth->fresh()->attempts)->toBe(1);
});

test('S-06: rejestracja z zaproszenia blokuje zaproszenie po błędnych kluczach', function () {
    [$invitation, $code] = securityInvitation();
    $wrong = $code === '000000' ? '111111' : '000000';
    $form = fn (string $key) => ['name' => 'Jan', 'email' => 'nowy@example.com', 'phone' => '+48600100200', 'password' => 'Haslo-1234567!', 'password_confirmation' => 'Haslo-1234567!', 'terms' => '1', 'privacy' => '1',
        'organization_invitation' => $invitation->code, 'invitation_key' => $key];

    foreach (range(1, 5) as $i) {
        $this->post('/register', $form($wrong))->assertSessionHasErrors('invitation_key');
        RateLimiter::clear('auth-throttle:register.store:ip:'.hash('sha256', '127.0.0.1'));
    }

    // locked: the right key no longer registers anybody
    $this->post('/register', $form($code))->assertSessionHasErrors('invitation_key');
    expect(User::where('email', 'nowy@example.com')->exists())->toBeFalse();
});

test('S-06: nowy klucz można zamówić tylko kilka razy na godzinę (bez logowania, samym linkiem)', function () {
    [$invitation] = securityInvitation();
    $resend = fn () => $this->post(route('organization-invitations.resend', $invitation));

    foreach (range(1, 3) as $i) {
        Carbon::setTestNow(now()->addSeconds(61));
        $resend()->assertSessionHasNoErrors();
    }

    // the interval of a minute is kept, but the hourly cap now refuses
    Carbon::setTestNow(now()->addSeconds(61));
    $resend()->assertSessionHasErrors('resend');

    // later (an hour on) it works again
    Carbon::setTestNow(now()->addHour());
    $resend()->assertSessionHasNoErrors();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------------------------------------------------
// S-07: security headers
// ---------------------------------------------------------------------------------------------------------------------

test('S-07: odpowiedzi mają nagłówki bezpieczeństwa (strona i API)', function () {
    foreach (['/', '/login', '/api/ping'] as $path) {
        $response = $this->get($path);

        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
            ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
            ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')
            ->and($response->headers->get('Strict-Transport-Security'))->toBeNull();
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// S-08: accounts with a published password could be created outside a development machine
// ---------------------------------------------------------------------------------------------------------------------

test('S-08: konta testowe z jawnym hasłem powstają tylko w środowisku local lub testing', function () {
    $before = User::where('email', 'like', 'dakar_%@test.pl')->count();

    foreach (['staging', 'production', 'demo'] as $environment) {
        app()->detectEnvironment(fn () => $environment);

        (new DakarSeeder)->run();

        expect(User::where('email', 'like', 'dakar_%@test.pl')->count())->toBe($before);
    }

    app()->detectEnvironment(fn () => 'testing');
});

// ---------------------------------------------------------------------------------------------------------------------
// S-09: a trade invitation outlived the rights of the person who sent it
// ---------------------------------------------------------------------------------------------------------------------

test('S-09: zaproszenie handlowe nieaktualne po utracie uprawnień przez zapraszającego', function () {
    $a = Organization::create(['name' => 'Dostawca']);
    $b = Organization::create(['name' => 'Odbiorca']);
    $hq = WarehouseHeadquarter::create(['organization_id' => $a->id, 'name' => 'HQ']);
    $w1 = WarehouseBranch::create(['organization_id' => $a->id, 'warehouse_headquarter_id' => $hq->id, 'name' => 'W1']);
    $leader = User::factory()->create(['role' => UserRole::WAREHOUSE_LEADER, 'organization_id' => $a->id, 'warehouse_headquarter_id' => $hq->id, 'warehouse_branch_id' => $w1->id]);
    $ownerB = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $b->id]);
    ShopHeadquarter::create(['organization_id' => $b->id, 'name' => 'Sklepy B']);
    $service = app(TradeRelationshipService::class);

    $invite = function () use ($service, $leader, $ownerB, $w1) {
        $service->invite($leader, ['inviter_role' => 'supplier', 'invitee_email' => $ownerB->email, 'units' => [UnitRefFor($w1)]]);
        $token = null;
        Notification::assertSentTo($ownerB, TradeInvitationNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    };

    // the leader is deactivated after sending: the invitation can no longer be accepted
    $token = $invite();
    $leader->forceFill(['is_active' => false])->save();
    $this->actingAs($ownerB)->post(route('trade.invitations.accept', $token), ['all_current' => true])->assertSessionHasErrors('invitation');
    expect(TradeRelationship::count())->toBe(0);

    // moved to another branch: the same
    $leader->forceFill(['is_active' => true])->save();
    $w2 = WarehouseBranch::create(['organization_id' => $a->id, 'warehouse_headquarter_id' => $hq->id, 'name' => 'W2']);
    $this->actingAs($leader)->delete(route('trade.partners.invitations.cancel', TradeInvitation::latest('id')->first()->id));
    $token = $invite();
    $leader->forceFill(['warehouse_branch_id' => $w2->id])->save();
    $this->actingAs($ownerB)->post(route('trade.invitations.accept', $token), ['all_current' => true])->assertSessionHasErrors('invitation');
    expect(TradeRelationship::count())->toBe(0);
});

function UnitRefFor(Model $unit): string
{
    return UnitRef::of($unit)->ref();
}

// ---------------------------------------------------------------------------------------------------------------------
// S-10: text written by a person went into e-mails as Markdown (links, formatting) under the platform's name
// ---------------------------------------------------------------------------------------------------------------------

test('S-10: tekst osoby w e-mailu platformy jest czystym tekstem bez odnośników i formatowania', function () {
    expect(MailText::plain('Kliknij [tutaj](https://zly.example/login) lub https://zly.example oraz www.zly.example <b>pilne</b> **teraz**'))
        ->not->toContain('zly.example')->not->toContain('](')->not->toContain('<b>')->not->toContain('**')
        ->and(MailText::plain("linia 1\n\n   linia 2"))->toBe('linia 1 linia 2')
        ->and(MailText::plain(str_repeat('a', 400), 50))->toHaveLength(51)
        ->and(MailText::plain(null))->toBe('');

    $notification = new TradeInvitationNotification(str_repeat('a', 64), 'Firma [x](https://zly.example)', 'przedstawianie ofert', 'Zaloguj się: https://zly.example/login **natychmiast**');
    $mail = $notification->toMail(User::factory()->make(['name' => 'Jan']));
    $text = implode("\n", $mail->introLines);

    expect($text)->not->toContain('zly.example')->not->toContain('**natychmiast**')->toContain('natychmiast');
});

// ---------------------------------------------------------------------------------------------------------------------
// S-13: links in e-mails followed the Host header of the request (password reset poisoning)
// ---------------------------------------------------------------------------------------------------------------------

test('S-13: w produkcji linki w e-mailach wskazują na APP_URL, nie na nagłówek Host', function () {
    $before = url('/');

    // outside production nothing changes (development hosts vary)
    App\Support\TrustedUrl::pin('https://platforma.example', false);
    expect(url('/'))->toBe($before);

    App\Support\TrustedUrl::pin('https://platforma.example', true);
    expect(route('password.reset', 'tok'))->toStartWith('https://platforma.example/reset-password/')
        ->and(url('/x'))->toBe('https://platforma.example/x');

    // a request with a foreign Host still gets a link to the configured site
    $this->get('http://zly.example/forgot-password');
    expect(route('login'))->toStartWith('https://platforma.example');

    // an unusable address pins nothing
    URL::forceRootUrl(null);
    App\Support\TrustedUrl::pin('nie-adres', true);
    expect(url('/'))->not->toContain('platforma.example');
    URL::forceRootUrl(null);
    URL::forceScheme(null);
});
