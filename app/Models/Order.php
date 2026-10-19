<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Support\UnitRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order placed from an offer version. Everything in it is a copy made when it was placed.
 *
 * @property int $id
 * @property string $number
 * @property int $buyer_organization_id
 * @property string $buyer_unit_ref
 * @property int $seller_organization_id
 * @property string $seller_unit_ref
 * @property int $offer_id
 * @property int $offer_version_id
 * @property int|null $placed_by
 * @property OrderStatus $status
 */
class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'delivery_address' => 'array',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'placed_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'offer_version_id');
    }

    public function buyerUnit(): ?UnitRef
    {
        return UnitRef::parse($this->buyer_unit_ref);
    }

    public function sellerUnit(): ?UnitRef
    {
        return UnitRef::parse($this->seller_unit_ref);
    }
}
