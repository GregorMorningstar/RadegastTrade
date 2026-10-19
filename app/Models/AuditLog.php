<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only record of the key actions of the trade module. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Wpisy dziennika audytowego są niezmienne.'));
        static::deleting(fn () => throw new LogicException('Wpisy dziennika audytowego są niezmienne.'));
    }
}
