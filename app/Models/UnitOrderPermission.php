<?php

namespace App\Models;

use App\Enums\OrderAbility;
use App\Support\UnitRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The right of a person to do something (place orders) for a unit. Granting and revoking keep the history: a revoked
 * row stays, with who revoked it and when.
 *
 * @property int $user_id
 * @property string $unit_ref
 * @property OrderAbility $ability
 */
class UnitOrderPermission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['ability' => OrderAbility::class, 'granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function unit(): ?UnitRef
    {
        return UnitRef::parse($this->unit_ref);
    }
}
