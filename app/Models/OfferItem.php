<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A position of an offer version: a copy of the product data and the price, frozen together with a published version.
 *
 * @property int $id
 * @property int $offer_version_id
 */
class OfferItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'vat_rate' => 'decimal:2',
            'min_qty' => 'decimal:3',
            'qty_step' => 'decimal:3',
            'max_qty' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (OfferItem $item) {
            if (! OfferVersion::query()->whereKey($item->offer_version_id)->where('status', 'draft')->exists()) {
                throw new LogicException('Pozycje opublikowanej wersji oferty są niezmienne.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'offer_version_id');
    }
}
