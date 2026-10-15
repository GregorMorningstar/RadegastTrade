<?php

namespace App\Repositories;

use App\Repositories\Contracts\NipRegistryRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;

/**
 * Client of the Ministry of Finance VAT taxpayer register API (https://wl-api.mf.gov.pl).
 */
class MfNipRegistryRepository implements NipRegistryRepositoryInterface
{
    private const BASE_URL = 'https://wl-api.mf.gov.pl/api/search/nip';

    private const TIMEOUT_SECONDS = 8;

    public function find(string $nip, ?CarbonInterface $date = null): array
    {
        $url = self::BASE_URL.'/'.rawurlencode($nip).'?date='.($date ?? now())->toDateString();

        try {
            $response = Http::acceptJson()
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                // locally (e.g. Laragon) the certificate bundle is often missing; in production we verify
                ->withOptions(['verify' => ! app()->environment('local')])
                ->get($url);

            // the API answers 400 / 404 for a number it does not know
            if (in_array($response->status(), [400, 404], true)) {
                return ['status' => self::NOT_FOUND];
            }

            if (! $response->successful()) {
                report(new \RuntimeException("MF NIP registry answered HTTP {$response->status()}."));

                return ['status' => self::UNAVAILABLE];
            }

            $json = $response->json();
            $subject = $json['result']['subject'] ?? ($json['result']['subjects'][0] ?? null);

            return is_array($subject) && $subject !== []
                ? ['status' => self::FOUND, 'subject' => $subject]
                : ['status' => self::NOT_FOUND];
        } catch (\Throwable $e) {
            report($e);

            return ['status' => self::UNAVAILABLE];
        }
    }
}
