<?php

namespace App\Services;

use App\Services\Contracts\NipServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Company data lookup by NIP in the Ministry of Finance VAT taxpayer register (the white list).
 *
 * Pochodzi z NipService z repozytorium workforce-production-manager. Zmiany:
 * - SSL verification is disabled only in the local environment (not in production),
 * - errors are not cached (a temporary API outage does not block a NIP for 24 hours),
 * - also returns REGON and splits the building and apartment numbers.
 */
class NipService implements NipServiceInterface
{
    protected string $base = 'https://wl-api.mf.gov.pl/api/search/nip';

    /**
     * Whether the NIP has 10 digits and a valid checksum.
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

        // Only successful responses are cached
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
                // locally (e.g. Laragon) the certificate bundle is often missing; in production we verify
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
     * Splits an address in the MF format: "STREET 3/5, 38-200 CITY".
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

        // "STREET 3/5" or "STREET 3 LOK. 5": building number (optionally with a letter) and apartment number
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
