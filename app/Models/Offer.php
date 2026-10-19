<?php

namespace App\Models;

use App\Enums\OfferStatus;
use App\Support\UnitRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trade offer of a unit (a warehouse). The content lives in versions; current_version_id is the published one.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $number
 * @property string $unit_ref
 * @property OfferStatus $status
 * @property bool $shared_internally
 * @property int|null $current_version_id
 */
class Offer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'shared_internally' => 'boolean',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<OfferVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(OfferVersion::class)->orderByDesc('version_no');
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'current_version_id');
    }

    /** @return HasMany<OfferShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(OfferShare::class);
    }

    public function unit(): ?UnitRef
    {
        return UnitRef::parse($this->unit_ref);
    }

    /** Whether the published version is past its validity date (worked out, never stored). */
    public function isExpired(): bool
    {
        $version = $this->currentVersion;

        return $version !== null && $version->valid_to !== null && $version->valid_to->isPast();
    }

    /** Whether the published version is in force now: published, started and not expired. */
    public function isOpen(): bool
    {
        $version = $this->currentVersion;

        return $this->status === OfferStatus::Published
            && $version !== null
            && ($version->valid_from === null || $version->valid_from->isPast())
            && ($version->valid_to === null || $version->valid_to->isFuture());
    }

    /** The status as shown to people: a published offer past its date is "expired". */
    public function displayStatus(): string
    {
        return $this->status === OfferStatus::Published && $this->isExpired() ? 'expired' : $this->status->value;
    }
}
