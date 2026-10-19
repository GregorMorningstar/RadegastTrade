<?php

namespace App\Enums;

enum TradeInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Oczekuje',
            self::Accepted => 'Przyjęte',
            self::Rejected => 'Odrzucone',
            self::Cancelled => 'Cofnięte',
            self::Expired => 'Wygasłe',
        };
    }
}
