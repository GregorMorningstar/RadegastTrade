<?php

namespace App\Services;

use App\Repositories\Contracts\NipRegistryRepositoryInterface;
use App\Services\Contracts\NipServiceInterface;
use App\Services\Contracts\NipVerificationServiceInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Verification of a NIP entered during registration (organizations, headquarters, branches):
 * checksum first, then the Ministry of Finance register through the repository.
 */
class NipVerificationService implements NipVerificationServiceInterface
{
    private const CACHE_HOURS = 24;

    public function __construct(
        private readonly NipRegistryRepositoryInterface $registry,
        private readonly NipServiceInterface $nipService,
    ) {
        //
    }

    public function verify(string $nip): array
    {
        $clean = preg_replace('/\D+/', '', $nip) ?? '';

        try {
            if (strlen($clean) !== 10) {
                return $this->result(self::INVALID_FORMAT, $clean);
            }

            if (! $this->nipService->validateNip($clean)) {
                return $this->result(self::INVALID_CHECKSUM, $clean);
            }

            if (! config('services.nip.check_registry', true)) {
                return $this->result(self::VALID, $clean);
            }

            return $this->lookup($clean);
        } catch (\Throwable $e) {
            report($e);

            return $this->result(self::UNAVAILABLE, $clean);
        }
    }

    /**
     * @return array{valid: bool, status: string, message: string, nip: string, company?: array{name: ?string, regon: ?string, vat_status: ?string}}
     */
    private function lookup(string $nip): array
    {
        $cacheKey = "nip_verification:{$nip}";

        // only definite answers are cached; an outage is retried on the next attempt
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $found = $this->registry->find($nip);

        if ($found['status'] === NipRegistryRepositoryInterface::UNAVAILABLE) {
            return $this->result(self::UNAVAILABLE, $nip);
        }

        $result = $found['status'] === NipRegistryRepositoryInterface::FOUND
            ? $this->result(self::VALID, $nip, [
                'name' => $found['subject']['name'] ?? $found['subject']['companyName'] ?? null,
                'regon' => $found['subject']['regon'] ?? null,
                'vat_status' => $found['subject']['statusVat'] ?? null,
            ])
            : $this->result(self::NOT_FOUND, $nip);

        Cache::put($cacheKey, $result, now()->addHours(self::CACHE_HOURS));

        return $result;
    }

    /**
     * @param  array{name: ?string, regon: ?string, vat_status: ?string}|null  $company
     * @return array{valid: bool, status: string, message: string, nip: string, company?: array{name: ?string, regon: ?string, vat_status: ?string}}
     */
    private function result(string $status, string $nip, ?array $company = null): array
    {
        $result = [
            'valid' => $status === self::VALID
                || ($status === self::UNAVAILABLE && ! config('services.nip.strict', false)),
            'status' => $status,
            'message' => $this->message($status),
            'nip' => $nip,
        ];

        if ($company !== null) {
            $result['company'] = $company;
        }

        return $result;
    }

    private function message(string $status): string
    {
        return match ($status) {
            self::VALID => 'Numer NIP jest poprawny.',
            self::INVALID_FORMAT => 'Numer NIP musi mieć 10 cyfr.',
            self::INVALID_CHECKSUM => 'Nieprawidłowy numer NIP (błędna suma kontrolna).',
            self::NOT_FOUND => 'Nie znaleziono firmy o tym numerze NIP w rejestrze podatników VAT.',
            default => 'Nie udało się zweryfikować numeru NIP w rejestrze. Spróbuj ponownie później.',
        };
    }
}
