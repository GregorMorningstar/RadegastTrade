<?php

namespace App\Enums;

/**
 * Side of a trade relationship: the one that presents offers (supplier) or the one that orders from them (buyer).
 */
enum TradeSide: string
{
    case Supplier = 'supplier';
    case Buyer = 'buyer';

    public function opposite(): self
    {
        return $this === self::Supplier ? self::Buyer : self::Supplier;
    }

    public function label(): string
    {
        return $this === self::Supplier ? 'Dostawca' : 'Odbiorca';
    }
}
