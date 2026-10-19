<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to an offer version, kept on the private disk (the path is never shown to people).
 *
 * @property int $id
 * @property int $offer_version_id
 */
class OfferAttachment extends Model
{
    protected $guarded = [];

    protected $hidden = ['path'];

    /** @return BelongsTo<OfferVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'offer_version_id');
    }
}
