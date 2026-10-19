<?php

namespace App\Enums;

enum TradeRelationshipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktywna',
            self::Suspended => 'Zawieszona',
            self::Ended => 'Zakończona',
        };
    }
}
