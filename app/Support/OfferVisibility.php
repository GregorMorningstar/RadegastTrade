<?php

namespace App\Support;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which offers a person may look at. The one place that decides it, used by the lists, the details, the attachments and
 * the policy, so no path can forget a rule. Built only from the signed-in user.
 *
 *  - the offers of the units the person manages (every status, drafts included);
 *  - the offers of the unit a worker works in (not drafts);
 *  - the offers published inside the organization (the deliberate exception for trade offers: it gives nothing but
 *    the offer itself);
 *  - the offers shared with a unit the person looks after (a manager sees what was shared with the units below them).
 * A draft is never visible to a recipient. A suspended or ended cooperation does not hide what was shared earlier.
 */
class OfferVisibility
{
    public function __construct(private readonly TradeAccess $access)
    {
        //
    }

    /** @return Builder<Offer> */
    public function query(User $user): Builder
    {
        if (! $this->access->canTrade($user)) {
            return Offer::query()->whereRaw('1 = 0');
        }

        $organizationId = (int) $user->organization_id;
        $managed = array_map(fn (UnitRef $unit) => $unit->ref(), $this->access->managedUnits($user));
        $viewed = array_map(fn (UnitRef $unit) => $unit->ref(), $this->access->viewUnits($user));

        return Offer::query()->where(function (Builder $query) use ($organizationId, $managed, $viewed) {
            $query->where(function (Builder $own) use ($organizationId, $managed) {
                $own->where('organization_id', $organizationId)->whereIn('unit_ref', $managed);
            })
                ->orWhere(function (Builder $own) use ($organizationId, $viewed) {
                    $own->where('organization_id', $organizationId)->where('status', '!=', 'draft')->whereIn('unit_ref', $viewed);
                })
                ->orWhere(function (Builder $internal) use ($organizationId) {
                    $internal->where('organization_id', $organizationId)->where('shared_internally', true)->where('status', 'published');
                })
                ->orWhere(function (Builder $external) use ($organizationId, $viewed) {
                    $external->where('status', '!=', 'draft')->whereHas('shares', fn (Builder $share) => $share
                        ->whereNull('revoked_at')
                        ->where('recipient_organization_id', $organizationId)
                        ->whereIn('unit_ref', $viewed));
                });
        });
    }

    public function canView(User $user, Offer $offer): bool
    {
        return $this->query($user)->whereKey($offer->id)->exists();
    }
}
