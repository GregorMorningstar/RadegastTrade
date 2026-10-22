<?php

namespace App\Models;

use App\Enums\TradeSide;
use App\Support\UnitRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A unit that is in the scope of a relationship, on one of its sides.
 *
 * @property TradeSide $side
 * @property string $unit_ref
 */
class TradeRelationshipUnit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['side' => TradeSide::class, 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<TradeRelationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(TradeRelationship::class, 'relationship_id');
    }

    /** @return BelongsTo<User, $this> */
    public function autoAddedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auto_added_by');
    }

    public function unit(): ?UnitRef
    {
        return UnitRef::parse($this->unit_ref);
    }
}
