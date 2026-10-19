<?php

namespace App\Support;

use App\Models\Order;
use App\Models\UnitOrderPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which orders a person may look at, and for which units they may place them.
 *
 * Purchases (the buyer side): the owner sees every order of the organization; a manager the orders of the units they
 * manage; anyone else only the orders they placed and those of the units they were authorised for. Sales (the seller
 * side): the owner and the managers of the selling unit. Everything else is simply not found.
 */
class OrderVisibility
{
    public function __construct(private readonly TradeAccess $access)
    {
        //
    }

    /** @return list<string> unit references the person was explicitly authorised to place orders for */
    public function authorisedUnits(User $user): array
    {
        if (! $this->access->canTrade($user)) {
            return [];
        }

        // the right belongs to a person of that very unit: one who works somewhere else now (moved to another branch or
        // to a warehouse) no longer has it, even though the record is still active
        $own = $this->access->ownUnit($user);

        if ($own === null) {
            return [];
        }

        return UnitOrderPermission::query()
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('unit_ref', $own->ref())
            ->whereNull('revoked_at')
            ->pluck('unit_ref')
            ->all();
    }

    /** Whether the person may place an order for the unit: the owner, a manager of it, or an authorised person. */
    public function canPlaceFor(User $user, UnitRef $unit): bool
    {
        if (! $this->access->canTrade($user) || $unit->find((int) $user->organization_id) === null) {
            return false;
        }

        return $this->access->isOwner($user)
            || $this->access->manages($user, $unit)
            || in_array($unit->ref(), $this->authorisedUnits($user), true);
    }

    /** @return Builder<Order> */
    public function purchases(User $user): Builder
    {
        if (! $this->access->canTrade($user)) {
            return Order::query()->whereRaw('1 = 0');
        }

        $query = Order::query()->where('buyer_organization_id', $user->organization_id);

        if ($this->access->isOwner($user)) {
            return $query;
        }

        $units = array_merge(
            array_map(fn (UnitRef $unit) => $unit->ref(), $this->access->managedUnits($user)),
            $this->authorisedUnits($user),
        );

        return $query->where(fn (Builder $own) => $own->where('placed_by', $user->id)->orWhereIn('buyer_unit_ref', $units));
    }

    /** @return Builder<Order> */
    public function sales(User $user): Builder
    {
        if (! $this->access->canTrade($user)) {
            return Order::query()->whereRaw('1 = 0');
        }

        $query = Order::query()->where('seller_organization_id', $user->organization_id);

        return $this->access->isOwner($user)
            ? $query
            : $query->whereIn('seller_unit_ref', array_map(fn (UnitRef $unit) => $unit->ref(), $this->access->managedUnits($user)));
    }

    /** @return Builder<Order> */
    public function any(User $user): Builder
    {
        $purchases = $this->purchases($user)->select('orders.id');
        $sales = $this->sales($user)->select('orders.id');

        return Order::query()->where(fn (Builder $query) => $query->whereIn('orders.id', $purchases)->orWhereIn('orders.id', $sales));
    }

    public function isSeller(User $user, Order $order): bool
    {
        return $this->sales($user)->whereKey($order->id)->exists();
    }

    public function isBuyer(User $user, Order $order): bool
    {
        return $this->purchases($user)->whereKey($order->id)->exists();
    }
}
