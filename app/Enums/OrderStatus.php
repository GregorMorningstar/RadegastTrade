<?php

namespace App\Enums;

/**
 * Order statuses. The buyer places (submitted), the seller confirms or rejects and then fulfils (sends), and the buyer
 * confirms the receipt. The buyer may cancel until the order is fulfilled.
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Fulfilled = 'fulfilled';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Szkic',
            self::Submitted => 'Złożone',
            self::Confirmed => 'Potwierdzone',
            self::Rejected => 'Odrzucone',
            self::Cancelled => 'Anulowane',
            self::Fulfilled => 'Zrealizowane (wysłane)',
            self::Received => 'Odebrane',
        };
    }

    /**
     * Statuses reachable from this one, and which side may do the change.
     *
     * @return array<string, 'seller'|'buyer'>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Submitted => ['confirmed' => 'seller', 'rejected' => 'seller', 'cancelled' => 'buyer'],
            self::Confirmed => ['fulfilled' => 'seller', 'cancelled' => 'buyer'],
            self::Fulfilled => ['received' => 'buyer'],
            default => [],
        };
    }
}
