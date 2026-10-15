<?php

namespace App\Enums;

/**
 * Position of a person in a unit (headquarters or branch): who manages it and who works in it.
 */
enum UnitPosition: string
{
    case Manager = 'manager';
    case Worker = 'worker';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Kierownik',
            self::Worker => 'Pracownik',
        };
    }
}
