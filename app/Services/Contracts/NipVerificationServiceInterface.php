<?php

namespace App\Services\Contracts;

interface NipVerificationServiceInterface
{
    public const VALID = 'valid';

    public const INVALID_FORMAT = 'invalid_format';

    public const INVALID_CHECKSUM = 'invalid_checksum';

    public const NOT_FOUND = 'not_found';

    /** The registry could not be queried; whether this passes depends on the strict mode. */
    public const UNAVAILABLE = 'unavailable';

    /**
     * Verifies a NIP typed during registration: its format and checksum, and then (when enabled in the config)
     * whether the company exists in the Ministry of Finance register. Never throws.
     *
     * "valid" is true for a verified NIP and, when the registry is down and the strict mode is off,
     * for a NIP with a correct checksum (an outage of the registry must not block registration).
     *
     * @return array{valid: bool, status: string, message: string, nip: string, company?: array{name: ?string, regon: ?string, vat_status: ?string}}
     */
    public function verify(string $nip): array;
}
