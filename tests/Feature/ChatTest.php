<?php

use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * Dwie osoby z jednej organizacji, osoba z innej organizacji i konto bez organizacji.
 *
 * @return array{a: User, b: User, outsider: User, loner: User, admin: User, org: Organization}
 */
function chatFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);

    return [
        'org' => $org,
        'a' => User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $org->id, 'name' => 'Ala']),
        'b' => User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $org->id, 'name' => 'Bartek']),
        'outsider' => User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $other->id, 'name' => 'Obcy']),
        'loner' => User::factory()->create(['role' => UserRole::Employee, 'name' => 'Samotny']),
        'admin' => User::factory()->create(['role' => UserRole::Admin, 'name' => 'Admin']),
    ];
}

test('lista kontaktów zawiera tylko osoby z mojej organizacji, bez mnie', function () {
    $f = chatFixture();
    // the platform staff accounts are created here: the test does not depend on seeded accounts
    User::factory()->create(['role' => UserRole::Manager, 'name' => 'Manager platformy']);

    $this->actingAs($f['a'])
        ->getJson(route('chat.contacts'))
        ->assertOk()
        ->assertJsonPath('me', $f['a']->id)
        ->assertJsonPath('unread', 0)
        ->assertJsonFragment(['id' => $f['b']->id, 'role' => UserRole::SHOP_OWNER->label()])
        ->assertJsonMissing(['id' => $f['outsider']->id]);

    // besides the organization, only the platform staff (administrators and managers) are on the list
    $roles = collect($this->getJson(route('chat.contacts'))->json('contacts'))->pluck('role')->unique()->sort()->values()->all();
    expect($roles)->toBe(collect([UserRole::Admin->label(), UserRole::Manager->label(), UserRole::SHOP_OWNER->label()])->sort()->values()->all());

    // konto bez organizacji nie ma z kim rozmawiać
    $this->actingAs($f['loner'])->getJson(route('chat.contacts'))->assertOk()->assertJsonCount(0, 'contacts');
});

test('wiadomość trafia do odbiorcy z organizacji, jest nieprzeczytana do czasu otwarcia rozmowy', function () {
    Event::fake([MessageSent::class]);
    $f = chatFixture();

    $this->actingAs($f['a'])
        ->postJson(route('chat.send', $f['b']), ['body' => '  Cześć!  '])
        ->assertCreated()
        ->assertJsonPath('message.body', 'Cześć!')
        ->assertJsonPath('message.sender_id', $f['a']->id)
        ->assertJsonPath('message.read_at', null);

    Event::assertDispatched(MessageSent::class, fn ($event) => $event->message->recipient_id === $f['b']->id
        && collect($event->broadcastOn())->map->name->all() === ["private-chat.{$f['b']->id}", "private-chat.{$f['a']->id}"]
        && $event->broadcastAs() === 'message.sent');

    $this->actingAs($f['b'])
        ->getJson(route('chat.contacts'))
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('contacts.0.unread', 1)
        ->assertJsonPath('contacts.0.last_message.body', 'Cześć!')
        ->assertJsonPath('contacts.0.last_message.mine', false);

    $this->getJson(route('chat.messages', $f['a']))
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.body', 'Cześć!');

    expect(Message::first()->read_at)->not->toBeNull();
    $this->getJson(route('chat.contacts'))->assertJsonPath('unread', 0);
});

test('nie można pisać do osoby spoza organizacji ani do siebie, a konto bez organizacji nie pisze nikomu', function () {
    Event::fake([MessageSent::class]);
    $f = chatFixture();

    $this->actingAs($f['a']);
    // a person of another organization does not exist for the sender
    $this->postJson(route('chat.send', $f['outsider']), ['body' => 'x'])->assertNotFound();
    $this->getJson(route('chat.messages', $f['outsider']))->assertNotFound();
    $this->postJson(route('chat.send', $f['a']), ['body' => 'x'])->assertForbidden();

    $this->actingAs($f['loner'])->postJson(route('chat.send', $f['b']), ['body' => 'x'])->assertForbidden();
    $this->actingAs($f['outsider'])->getJson(route('chat.messages', $f['a']))->assertNotFound();

    expect(Message::count())->toBe(0);
    Event::assertNotDispatched(MessageSent::class);
});

test('treść wiadomości jest walidowana', function () {
    $f = chatFixture();
    $this->actingAs($f['a']);

    $this->postJson(route('chat.send', $f['b']), ['body' => ''])->assertJsonValidationErrors('body');
    $this->postJson(route('chat.send', $f['b']), ['body' => str_repeat('a', 2001)])->assertJsonValidationErrors('body');
    $this->postJson(route('chat.send', $f['b']))->assertJsonValidationErrors('body');
});

test('rozmowę widzą tylko jej uczestnicy', function () {
    $f = chatFixture();
    $c = User::factory()->create(['role' => UserRole::SHOP_LEADER, 'organization_id' => $f['org']->id]);

    $this->actingAs($f['a'])->postJson(route('chat.send', $f['b']), ['body' => 'tajne'])->assertCreated();

    // trzecia osoba z organizacji nie dostaje cudzej rozmowy
    $this->actingAs($c)->getJson(route('chat.messages', $f['a']))->assertOk()->assertJsonCount(0, 'messages');
    $this->getJson(route('chat.messages', $f['b']))->assertOk()->assertJsonCount(0, 'messages');
});

test('aktywność: kontakt jest aktywny przez dwie minuty od ostatniego zapytania', function () {
    $f = chatFixture();

    $f['b']->forceFill(['last_seen_at' => now()->subSeconds(30)])->save();
    $this->actingAs($f['a'])->getJson(route('chat.contacts'))->assertJsonPath('contacts.0.active', true);

    $f['b']->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
    $this->getJson(route('chat.contacts'))->assertJsonPath('contacts.0.active', false);

    // samo wejście zalogowanego użytkownika odświeża jego aktywność
    $this->actingAs($f['b'])->getJson(route('chat.contacts'));
    expect($f['b']->refresh()->last_seen_at->gt(now()->subSeconds(5)))->toBeTrue();
});

test('aktywni kontakty są na początku listy', function () {
    $f = chatFixture();
    $c = User::factory()->create(['role' => UserRole::SHOP_LEADER, 'organization_id' => $f['org']->id, 'name' => 'Cezary', 'last_seen_at' => now()]);
    $f['b']->forceFill(['last_seen_at' => now()->subHour()])->save();

    $ids = collect($this->actingAs($f['a'])->getJson(route('chat.contacts'))->json('contacts'))
        ->pluck('id')
        ->filter(fn ($id) => in_array($id, [$c->id, $f['b']->id], true))
        ->values()
        ->all();

    expect($ids)->toBe([$c->id, $f['b']->id]);
});

test('strona czatu jest dostępna po zalogowaniu, dla konta bez organizacji z informacją', function () {
    $f = chatFixture();

    $this->get(route('chat.index'))->assertRedirect(route('login'));

    $this->actingAs($f['a'])->get(route('chat.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('chat/index')->where('canChat', true)->where('organizationName', 'Org'));
    $this->actingAs($f['loner'])->get(route('chat.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('canChat', false));
});

test('zdjęcie profilowe: domyślny awatar, wgranie, zmiana i usunięcie', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    expect($user->avatar_url)->toEndWith('/images/default-avatar.svg');

    $this->actingAs($user)
        ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('a.png', 200, 200)])
        ->assertRedirect(route('profile.edit'));

    $first = $user->refresh()->avatar_path;
    Storage::disk('public')->assertExists($first);
    expect($user->avatar_url)->toContain($first);

    $this->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('b.jpg', 300, 300)]);
    $second = $user->refresh()->avatar_path;
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    $this->delete(route('profile.avatar.destroy'))->assertRedirect(route('profile.edit'));
    Storage::disk('public')->assertMissing($second);
    expect($user->refresh()->avatar_path)->toBeNull()->and($user->avatar_url)->toEndWith('default-avatar.svg');
});

test('zdjęcie profilowe musi być obrazem o rozsądnym rozmiarze', function (callable $file) {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => $file()])->assertSessionHasErrors('avatar');
    expect($user->refresh()->avatar_path)->toBeNull();
})->with([
    'plik tekstowy' => [fn () => UploadedFile::fake()->create('a.txt', 10, 'text/plain')],
    'za duży' => [fn () => UploadedFile::fake()->image('a.png', 200, 200)->size(3000)],
    'za mały' => [fn () => UploadedFile::fake()->image('a.png', 20, 20)],
    'skrypt jako obraz' => [fn () => UploadedFile::fake()->create('a.php', 10, 'application/x-php')],
]);

test('administrator pisze z osobami z dowolnej organizacji, a one widzą go na liście', function () {
    Event::fake([MessageSent::class]);
    $f = chatFixture();

    // the administrator has no organization, yet sees people from organizations and can write to them
    $this->actingAs($f['admin'])->getJson(route('chat.contacts'))
        ->assertJsonCount(3, 'contacts');

    $this->postJson(route('chat.send', $f['outsider']), ['body' => 'Dzień dobry'])
        ->assertCreated()
        ->assertJsonPath('message.body', 'Dzień dobry');

    expect(Message::first()->organization_id)->toBe($f['outsider']->organization_id);

    // the person answers and sees the administrator among contacts
    $this->actingAs($f['outsider'])->getJson(route('chat.contacts'))
        ->assertJsonPath('unread', 1)
        ->assertJsonFragment(['id' => $f['admin']->id, 'name' => 'Admin']);
    $this->postJson(route('chat.send', $f['admin']), ['body' => 'Cześć'])->assertCreated();
    $this->actingAs($f['admin'])->getJson(route('chat.messages', $f['outsider']))->assertJsonCount(2, 'messages');

    // an account without an organization still cannot talk to the administrator, and users cannot see each other across organizations
    $this->actingAs($f['loner'])->postJson(route('chat.send', $f['admin']), ['body' => 'x'])->assertForbidden();
    $this->actingAs($f['a'])->postJson(route('chat.send', $f['outsider']), ['body' => 'x'])->assertNotFound();
});
