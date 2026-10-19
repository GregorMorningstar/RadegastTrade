<?php

namespace App\Models;

use App\Support\UnitRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An offer shared with one unit of another organization. Seeing the offer and ordering from it are separate
 * (allow_order). A revoked share stays as a record.
 *
 * @property int $id
 * @property int $offer_id
 * @property int $relationship_id
 * @property int $recipient_organization_id
 * @property string $unit_ref
 * @property bool $allow_order
 */
class OfferShare extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'allow_order' => 'boolean',
            'shared_at' => 'datetime',
            'notified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<TradeRelationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(TradeRelationship::class, 'relationship_id');
    }

    public function unit(): ?UnitRef
    {
        return UnitRef::parse($this->unit_ref);
    }
}
