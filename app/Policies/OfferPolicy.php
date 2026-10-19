<?php

namespace App\Policies;

use App\Models\Offer;
use App\Models\User;
use App\Support\OfferVisibility;
use App\Support\TradeAccess;
use App\Support\UnitRef;

/**
 * Who may do what with offers (the offers.* rights of the specification). Looking, changing and ordering are separate:
 *  - view: OfferVisibility (own units, the organization's published offers, offers shared with the person's units);
 *  - create / update / publish / share: the person manages the warehouse unit that presents the offer; only warehouses
 *    present offers (shops only buy);
 *  - ordering is decided by OrderService, never by the right to look.
 */
class OfferPolicy
{
    public function __construct(private readonly TradeAccess $access, private readonly OfferVisibility $visibility)
    {
        //
    }

    public function viewAny(User $user): bool
    {
        return $this->access->canTrade($user);
    }

    /** Whether the person manages at least one warehouse unit, so may present offers. */
    public function create(User $user): bool
    {
        return collect($this->access->managedUnits($user))->contains(fn (UnitRef $unit) => $unit->isWarehouse());
    }

    public function view(User $user, Offer $offer): bool
    {
        return $this->visibility->canView($user, $offer);
    }

    /** Update, publish, withdraw, archive and share (offers.update / publish / share_external) share one rule. */
    public function manage(User $user, Offer $offer): bool
    {
        $unit = $offer->unit();

        return $unit !== null
            && $unit->isWarehouse()
            && $offer->organization_id === $user->organization_id
            && $this->access->manages($user, $unit);
    }
}
