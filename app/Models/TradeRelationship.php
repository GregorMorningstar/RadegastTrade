<?php

namespace App\Models;

use App\Enums\TradeRelationshipStatus;
use App\Enums\TradeSide;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Directional trade cooperation: the supplier organization presents offers to the buyer organization, within the units
 * listed in trade_relationship_units. Rows are created only by TradeRelationshipService.
 *
 * @property int $id
 * @property int $supplier_organization_id
 * @property int $buyer_organization_id
 * @property TradeRelationshipStatus $status
 */
class TradeRelationship extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => TradeRelationshipStatus::class,
            'started_at' => 'datetime',
            'suspended_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_id');
    }

    /** @return BelongsTo<TradeInvitation, $this> the invitation that started (or restarted) the cooperation */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(TradeInvitation::class, 'invitation_id');
    }

    /** @return HasMany<TradeRelationshipUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(TradeRelationshipUnit::class, 'relationship_id');
    }

    public function isActive(): bool
    {
        return $this->status === TradeRelationshipStatus::Active;
    }

    public function organizationOf(TradeSide $side): int
    {
        return $side === TradeSide::Supplier ? $this->supplier_organization_id : $this->buyer_organization_id;
    }

    /** The side the organization is on (null when it is not a party). */
    public function sideOf(int $organizationId): ?TradeSide
    {
        return match ($organizationId) {
            $this->supplier_organization_id => TradeSide::Supplier,
            $this->buyer_organization_id => TradeSide::Buyer,
            default => null,
        };
    }
}
