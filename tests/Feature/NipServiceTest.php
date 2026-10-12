<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\NipService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function mfResponse(array $subject = []): array
{
    return ['result' => ['subject' => array_merge([
        'name' => 'FIRMA TESTOWA SPÓŁKA Z O.O.',
        'nip' => '1234563218',
        'regon' => '123456785',
        'workingAddress' => 'POLNA 3/5, 38-200 JASŁO',
    ], $subject)]];
}

test('NIP jest sprawdzany pod kątem sumy kontrolnej', function (string $nip, bool $valid) {
    expect((new NipService)->validateNip($nip))->toBe($valid);
})->with([
    ['1234563218', true],
    ['123-456-32-18', true],
    ['5260250995', true],
    ['1234567890', false],
    ['123', false],
    ['', false],
]);

test('dane firmy są pobierane z rejestru MF i rozdzielane na pola formularza', function () {
    Http::fake(['wl-api.mf.gov.pl/*' => Http::response(mfResponse())]);

    $result = (new NipService)->fetchByNip('123-456-32-18');

    expect($result)->toMatchArray([
        'ok' => true,
        'nip' => '1234563218',
        'name' => 'FIRMA TESTOWA SPÓŁKA Z O.O.',
        'regon' => '123456785',
        'street' => 'POLNA',
        'building_number' => '3',
        'apartment_number' => '5',
        'zip' => '38-200',
        'city' => 'JASŁO',
    ]);
});

test('adres bez numeru lokalu i z literą w numerze budynku', function () {
    Http::fake(['wl-api.mf.gov.pl/*' => Http::response(mfResponse(['workingAddress' => 'UL. KOŚCIUSZKI 12A, 00-001 WARSZAWA']))]);

    $result = (new NipService)->fetchByNip('1234563218');

    expect($result['street'])->toBe('UL. KOŚCIUSZKI')
        ->and($result['building_number'])->toBe('12A')
        ->and($result['apartment_number'])->toBeNull();
});

test('nieprawidłowy NIP nie wywołuje rejestru', function () {
    Http::fake();

    expect((new NipService)->fetchByNip('1234567890'))->toMatchArray(['ok' => false, 'error' => 'Nieprawidłowy NIP']);

    Http::assertNothingSent();
});

test('udane odpowiedzi są cache\'owane, a błędy nie', function () {
    Cache::flush();
    Http::fakeSequence('wl-api.mf.gov.pl/*')
        ->push([], 500)
        ->push(mfResponse());

    $service = new NipService;

    // pierwsza próba kończy się błędem API i nie trafia do cache
    expect($service->fetchByNip('1234563218')['ok'])->toBeFalse();

    // druga próba pobiera dane, trzecia bierze je z cache (bez ruchu sieciowego)
    expect($service->fetchByNip('1234563218')['ok'])->toBeTrue();
    expect($service->fetchByNip('1234563218')['ok'])->toBeTrue();

    Http::assertSentCount(2);
});

test('brak podmiotu w rejestrze i awaria połączenia dają czytelny błąd', function () {
    Cache::flush();
    Http::fake(['wl-api.mf.gov.pl/*' => Http::response(['result' => ['subject' => null]])]);

    expect((new NipService)->fetchByNip('1234563218')['error'])->toBe('Nie znaleziono danych firmy');

    Cache::flush();
    Http::fake(['wl-api.mf.gov.pl/*' => fn () => throw new Illuminate\Http\Client\ConnectionException('timeout')]);

    expect((new NipService)->fetchByNip('1234563218')['ok'])->toBeFalse();
});

test('właściciel organizacji wyszukuje dane po NIP w formularzu', function () {
    Cache::flush();
    Http::fake(['wl-api.mf.gov.pl/*' => Http::response(mfResponse())]);
    $owner = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER]);

    $this->actingAs($owner)
        ->getJson(route('platform.organization.nip-lookup', ['nip' => '1234563218']))
        ->assertOk()
        ->assertJson(['ok' => true, 'city' => 'JASŁO', 'zip' => '38-200', 'regon' => '123456785']);
});

test('wyszukiwanie po NIP jest dostępne tylko dla właściciela organizacji', function () {
    $this->getJson(route('platform.organization.nip-lookup', ['nip' => '1234563218']))->assertUnauthorized();

    $this->actingAs(User::factory()->create(['role' => UserRole::SHOP_WORKER]))
        ->getJson(route('platform.organization.nip-lookup', ['nip' => '1234563218']))
        ->assertForbidden();
});
