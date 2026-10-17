<?php

namespace App\Enums;

/**
 * Priority of a task.
 */
enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Niski',
            self::Normal => 'Normalny',
            self::High => 'Wysoki',
            self::Urgent => 'Pilny',
        };
    }
}
