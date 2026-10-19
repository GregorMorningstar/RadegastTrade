<?php

namespace App\Enums;

/**
 * Stored status of an offer. "Expired" is not stored: it is worked out from the validity date of the current version
 * (see Offer::isExpired()), so it is always right without a background job.
 */
enum OfferStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Withdrawn = 'withdrawn';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Szkic',
            self::Published => 'Opublikowana',
            self::Withdrawn => 'Wycofana',
            self::Archived => 'Zarchiwizowana',
        };
    }
}
