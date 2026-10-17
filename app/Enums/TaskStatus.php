<?php

namespace App\Enums;

/**
 * Status of a task.
 */
enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'Do zrobienia',
            self::InProgress => 'W trakcie',
            self::Done => 'Zrobione',
        };
    }
}
