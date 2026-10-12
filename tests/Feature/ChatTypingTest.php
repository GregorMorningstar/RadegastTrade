<?php

use App\Enums\UserRole;
use App\Events\UserTyping;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * Dwie osoby z jednej organizacji, trzecia z tej samej oraz osoba z innej organizacji.
 *
 * @return array{a: User, b: User, c: User, outsider: User}
 */
function typingFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);

    return [
        'a' => User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $org->id]),
        'b' => User::factory()->create(['role' => UserRole::SHOP_OWNER, 'organization_id' => $org->id]),
        'c' => User::factory()->create(['role' => UserRole::WAREHOUSE_OWNER, 'organization_id' => $org->id]),
        'outsider' => User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => $other->id]),
    ];
}

test('sygnał pisania widzi tylko adresat, w kontaktach i w rozmowie', function () {
    Event::fake([UserTyping::class]);
    $f = typingFixture();

    $this->actingAs($f['a'])->postJson(route('chat.typing', $f['b']))->assertNoContent();

    Event::assertDispatched(UserTyping::class, fn ($event) => $event->senderId === $f['a']->id
        && $event->recipientId === $f['b']->id
        && $event->broadcastAs() === 'user.typing'
        && collect($event->broadcastOn())->map->name->all() === ["private-chat.{$f['b']->id}"]
        && $event->broadcastWith() === ['user_id' => $f['a']->id]);

    // adresat widzi, że ktoś do niego pisze
    $this->actingAs($f['b'])->getJson(route('chat.contacts'))
        ->assertJsonPath('contacts.0.id', $f['a']->id)
        ->assertJsonPath('contacts.0.typing', true);
    $this->getJson(route('chat.messages', $f['a']))->assertJsonPath('typing', true);

    // trzecia osoba i sam piszący nie widzą wskaźnika
    $this->actingAs($f['c'])->getJson(route('chat.messages', $f['a']))->assertJsonPath('typing', false);
    $this->actingAs($f['a'])->getJson(route('chat.messages', $f['b']))->assertJsonPath('typing', false);
    $this->getJson(route('chat.contacts'))->assertJsonMissing(['typing' => true]);
});

test('wskaźnik pisania wygasa po kilku sekundach', function () {
    Event::fake([UserTyping::class]);
    $f = typingFixture();

    $this->actingAs($f['a'])->postJson(route('chat.typing', $f['b']))->assertNoContent();

    $this->actingAs($f['b'])->getJson(route('chat.messages', $f['a']))->assertJsonPath('typing', true);

    $this->travel(7)->seconds();

    $this->getJson(route('chat.messages', $f['a']))->assertJsonPath('typing', false);
});

test('sygnał pisania działa tylko w obrębie organizacji', function () {
    Event::fake([UserTyping::class]);
    $f = typingFixture();

    $this->actingAs($f['a'])->postJson(route('chat.typing', $f['outsider']))->assertForbidden();
    $this->postJson(route('chat.typing', $f['a']))->assertForbidden();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson(route('chat.typing', $f['a']))->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->postJson(route('chat.typing', $f['a']))->assertUnauthorized();

    Event::assertNotDispatched(UserTyping::class);
});
