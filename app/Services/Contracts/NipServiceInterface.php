<?php

namespace App\Services\Contracts;

interface NipServiceInterface
{
    /**
     * Whether the NIP has 10 digits and a valid checksum.
     */
    public function validateNip(string $nip): bool;

    /**
     * @return array{ok: bool, error?: string, nip?: string, name?: string|null, regon?: string|null, address?: string|null, street?: string|null, building_number?: string|null, apartment_number?: string|null, zip?: string|null, city?: string|null}
     */
    public function fetchByNip(string $nip): array;
}
