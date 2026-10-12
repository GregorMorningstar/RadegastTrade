<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Wyszukiwanie danych firmy po NIP w Wykazie podatników VAT Ministerstwa Finansów (biała lista).
 *
 * Pochodzi z NipService z repozytorium workforce-production-manager. Zmiany:
 * - weryfikacja SSL jest wyłączana tylko w środowisku lokalnym (nie na produkcji),
 * - błędy nie są cache'owane (chwilowa awaria API nie blokuje NIP-u na 24 godziny),
 * - zwraca też REGON oraz rozdziela numer budynku i lokalu.
 */
class NipService
{
    protected string $base = 'https://wl-api.mf.gov.pl/api/search/nip';

    /**
     * Czy NIP ma 10 cyfr i poprawną sumę kontrolną.
     */
    public function validateNip(string $nip): bool
    {
        $clean = preg_replace('/\D+/', '', $nip);

        if (strlen($clean) !== 10) {
            return false;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += $weights[$i] * (int) $clean[$i];
        }

        $control = $sum % 11;

        return $control !== 10 && $control === (int) $clean[9];
    }

    /**
     * @return array{ok: bool, error?: string, nip?: string, name?: string|null, regon?: string|null, address?: string|null, street?: string|null, building_number?: string|null, apartment_number?: string|null, zip?: string|null, city?: string|null}
     */
    public function fetchByNip(string $nip): array
    {
        $nipClean = preg_replace('/\D+/', '', $nip);

        if (! $this->validateNip($nipClean)) {
            return ['ok' => false, 'error' => 'Nieprawidłowy NIP'];
        }

        $cacheKey = "nip_lookup:{$nipClean}";

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $result = $this->request($nipClean);

        // Cache'ujemy tylko udane odpowiedzi
        if ($result['ok']) {
            Cache::put($cacheKey, $result, now()->addHours(24));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $nipClean): array
    {
        $url = "{$this->base}/{$nipClean}?date=".now()->toDateString();

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                // lokalnie (np. Laragon) często brakuje pakietu certyfikatów; na produkcji weryfikujemy
                ->withOptions(['verify' => ! app()->environment('local')])
                ->get($url);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Nie udało się połączyć z rejestrem MF'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => "Błąd API MF ({$response->status()})"];
        }

        $json = $response->json();
        $subject = $json['result']['subject'] ?? ($json['result']['subjects'][0] ?? null);

        if (! $subject) {
            return ['ok' => false, 'error' => 'Nie znaleziono danych firmy'];
        }

        $address = $subject['workingAddress'] ?? ($subject['residenceAddress'] ?? null);
        [$street, $building, $apartment, $zip, $city] = $this->splitAddress((string) $address);

        return [
            'ok' => true,
            'nip' => $subject['nip'] ?? $nipClean,
            'name' => $subject['name'] ?? $subject['companyName'] ?? null,
            'regon' => $subject['regon'] ?? null,
            'address' => $address,
            'street' => $street,
            'building_number' => $building,
            'apartment_number' => $apartment,
            'zip' => $zip,
            'city' => $city,
        ];
    }

    /**
     * Rozdziela adres w formacie MF: "ULICA 3/5, 38-200 MIEJSCOWOŚĆ".
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string}
     */
    private function splitAddress(string $address): array
    {
        if ($address === '') {
            return [null, null, null, null, null];
        }

        $parts = array_map('trim', explode(',', $address, 2));
        $line = $parts[0] ?? '';
        $street = $line;
        $building = null;
        $apartment = null;
        $zip = null;
        $city = null;

        // "ULICA 3/5" albo "ULICA 3 LOK. 5": numer budynku (opcjonalnie z literą) i numer lokalu
        if (preg_match('/^(.*?)\s+(\d+[A-Za-z]?)(?:\s*\/\s*|\s+(?:LOK\.?|M\.?)\s*)(\S+)$/iu', $line, $m)) {
            [$street, $building, $apartment] = [trim($m[1]), $m[2], $m[3]];
        } elseif (preg_match('/^(.*?)\s+(\d+[A-Za-z]?)$/u', $line, $m)) {
            [$street, $building] = [trim($m[1]), $m[2]];
        }

        if (! empty($parts[1]) && preg_match('/(\d{2}-\d{3})\s+(.+)/u', $parts[1], $m)) {
            $zip = $m[1];
            $city = $m[2];
        }

        return [$street ?: null, $building, $apartment, $zip, $city];
    }
}
