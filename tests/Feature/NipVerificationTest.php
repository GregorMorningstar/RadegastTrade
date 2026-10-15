<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Repositories\Contracts\NipRegistryRepositoryInterface;
use App\Rules\RegisteredNip;
use App\Services\Contracts\NipVerificationServiceInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

// 6852339117 and 5260250995 have a valid checksum
const KNOWN_NIP = '5260250995';

beforeEach(function () {
    config(['services.nip.check_registry' => true, 'services.nip.strict' => false]);
});

function registryAnswers(array $subject = ['name' => 'FIRMA SP. Z O.O.', 'nip' => KNOWN_NIP, 'regon' => '123456785', 'statusVat' => 'Czynny']): void
{
    Http::fake(['wl-api.mf.gov.pl/*' => Http::response(['result' => ['subject' => $subject]])]);
}

test('poprawny NIP znaleziony w rejestrze MF jest zweryfikowany i zwraca dane firmy', function () {
    registryAnswers();

    $result = app(NipVerificationServiceInterface::class)->verify('526-025-09-95');

    expect($result)->toMatchArray(['valid' => true, 'status' => 'valid', 'nip' => KNOWN_NIP])
        ->and($result['company'])->toBe(['name' => 'FIRMA SP. Z O.O.', 'regon' => '123456785', 'vat_status' => 'Czynny']);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/search/nip/'.KNOWN_NIP));
});

test('zły format i zła suma kontrolna są odrzucane bez pytania rejestru', function (string $nip, string $status) {
    Http::fake();

    $result = app(NipVerificationServiceInterface::class)->verify($nip);

    expect($result['valid'])->toBeFalse()->and($result['status'])->toBe($status);
    Http::assertNothingSent();
})->with([
    'za krótki' => ['123', 'invalid_format'],
    'litery' => ['abc', 'invalid_format'],
    'zła suma kontrolna' => ['1234567890', 'invalid_checksum'],
]);

test('NIP nieznany w rejestrze jest odrzucany', function (callable $fake) {
    $fake();

    $result = app(NipVerificationServiceInterface::class)->verify(KNOWN_NIP);

    expect($result)->toMatchArray(['valid' => false, 'status' => 'not_found'])
        ->and($result['message'])->toContain('Nie znaleziono firmy');
})->with([
    'pusty wynik' => [fn () => Http::fake(['*' => Http::response(['result' => ['subject' => null]])])],
    'HTTP 404' => [fn () => Http::fake(['*' => Http::response([], 404)])],
    'HTTP 400' => [fn () => Http::fake(['*' => Http::response(['code' => 'WL-112'], 400)])],
]);

test('awaria rejestru nie blokuje rejestracji, a w trybie ścisłym ją blokuje', function (callable $fake) {
    $fake();
    $service = app(NipVerificationServiceInterface::class);

    expect($service->verify(KNOWN_NIP))->toMatchArray(['valid' => true, 'status' => 'unavailable']);

    config(['services.nip.strict' => true]);
    expect($service->verify(KNOWN_NIP))->toMatchArray(['valid' => false, 'status' => 'unavailable']);
})->with([
    'błąd serwera' => [fn () => Http::fake(['*' => Http::response([], 500)])],
    'brak połączenia' => [fn () => Http::fake(['*' => fn () => throw new ConnectionException('timeout')])],
]);

test('repozytorium nigdy nie rzuca wyjątku, tylko zwraca status', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('down')]);

    expect(app(NipRegistryRepositoryInterface::class)->find(KNOWN_NIP))->toBe(['status' => 'unavailable']);
});

test('zdecydowane odpowiedzi są zapamiętywane w cache', function () {
    registryAnswers();
    $service = app(NipVerificationServiceInterface::class);

    $service->verify(KNOWN_NIP);
    $service->verify(KNOWN_NIP);

    Http::assertSentCount(1);
});

test('awarie rejestru nie są zapamiętywane w cache', function () {
    Http::fake(['*' => Http::response([], 500)]);
    $service = app(NipVerificationServiceInterface::class);

    $service->verify(KNOWN_NIP);
    $service->verify(KNOWN_NIP);

    Http::assertSentCount(2);
});

test('wyłączone sprawdzanie rejestru weryfikuje tylko sumę kontrolną', function () {
    config(['services.nip.check_registry' => false]);
    Http::fake();

    expect(app(NipVerificationServiceInterface::class)->verify(KNOWN_NIP)['valid'])->toBeTrue();
    Http::assertNothingSent();
});

test('reguła RegisteredNip przepuszcza puste pole i zwraca komunikaty z serwisu', function () {
    Http::fake(['*' => Http::response(['result' => ['subject' => null]])]);
    $check = fn ($value) => Validator::make(['NIP' => $value], ['NIP' => [new RegisteredNip]]);

    expect($check(null)->passes())->toBeTrue()
        ->and($check('1234567890')->errors()->first('NIP'))->toContain('suma kontrolna')
        ->and($check(KNOWN_NIP)->errors()->first('NIP'))->toContain('Nie znaleziono firmy');
});

test('rejestracja organizacji z NIP-em spoza rejestru MF jest odrzucona', function () {
    Http::fake(['*' => Http::response(['result' => ['subject' => null]])]);
    $owner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER]);

    $this->actingAs($owner)
        ->post(route('platform.organization.setup.store'), ['name' => 'Firma', 'NIP' => KNOWN_NIP])
        ->assertSessionHasErrors('NIP');

    expect(Organization::count())->toBe(0);
});

test('rejestracja organizacji z NIP-em znalezionym w rejestrze MF przechodzi', function () {
    registryAnswers();
    $owner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER]);

    $this->actingAs($owner)
        ->post(route('platform.organization.setup.store'), ['name' => 'Firma', 'NIP' => KNOWN_NIP])
        ->assertSessionHasNoErrors();

    expect(Organization::where('NIP', KNOWN_NIP)->exists())->toBeTrue();
});
