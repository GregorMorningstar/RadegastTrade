<?php

use App\Models\User;

test('telefon jest obowiązkowy przy rejestracji', function () {
    [$invitation, $code] = newOrganizationInvitationFor('owner@example.com');

    $payload = registrationPayload($invitation, $code);
    unset($payload['phone']);

    $this->post(route('register.store'), $payload)->assertSessionHasErrors('phone');
    $this->post(route('register.store'), [...$payload, 'phone' => '   '])->assertSessionHasErrors('phone');

    expect(User::where('email', 'owner@example.com')->exists())->toBeFalse();
});

test('telefon musi mieć poprawny format', function (string $phone) {
    [$invitation, $code] = newOrganizationInvitationFor('owner@example.com');

    $this->post(route('register.store'), registrationPayload($invitation, $code, ['phone' => $phone]))
        ->assertSessionHasErrors('phone');
})->with(['za krótki' => '12345', 'litery' => 'abcdefghi', 'za długi' => '+4860010020012345678']);

test('telefon z formularza jest zapisywany w postaci międzynarodowej', function () {
    [$invitation, $code] = newOrganizationInvitationFor('owner@example.com');

    $this->post(route('register.store'), registrationPayload($invitation, $code, ['phone' => '600 100-200']))
        ->assertSessionHasNoErrors();

    expect(User::where('email', 'owner@example.com')->first()->phone)->toBe('+48600100200');
});
