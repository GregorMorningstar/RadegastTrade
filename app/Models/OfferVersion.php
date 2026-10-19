<?php

namespace App\Models;

use App\Enums\OfferVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One version of an offer. A draft can be edited; a published version is frozen for good (the only change allowed is
 * becoming "superseded" when a newer version is published), so an order always points at what the buyer saw.
 *
 * @property int $id
 * @property int $offer_id
 * @property int $version_no
 * @property OfferVersionStatus $status
 */
class OfferVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OfferVersionStatus::class,
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (OfferVersion $version) {
            if ($version->getRawOriginal('status') === OfferVersionStatus::Draft->value) {
                return;
            }

            $changed = array_diff(array_keys($version->getDirty()), ['status', 'updated_at']);

            if ($changed !== [] || ($version->isDirty('status') && $version->status !== OfferVersionStatus::Superseded)) {
                throw new LogicException('Opublikowana wersja oferty jest niezmienna. Utwórz nową wersję.');
            }
        });

        static::deleting(function (OfferVersion $version) {
            if ($version->status !== OfferVersionStatus::Draft) {
                throw new LogicException('Opublikowanej wersji oferty nie można usunąć.');
            }
        });
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return HasMany<OfferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<OfferAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(OfferAttachment::class);
    }

    public function isDraft(): bool
    {
        return $this->status === OfferVersionStatus::Draft;
    }
}
