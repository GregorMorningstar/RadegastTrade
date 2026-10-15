<?php

namespace App\Repositories\Contracts;

use Carbon\CarbonInterface;

interface NipRegistryRepositoryInterface
{
    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    /** The registry could not be queried (network error, timeout, server error). */
    public const UNAVAILABLE = 'unavailable';

    /**
     * Looks the company up by NIP in the Ministry of Finance VAT taxpayer register (the white list).
     * Never throws: connection problems are reported as the UNAVAILABLE status.
     *
     * @param  string  $nip  10 digits
     * @return array{status: string, subject?: array<string, mixed>}
     */
    public function find(string $nip, ?CarbonInterface $date = null): array;
}
