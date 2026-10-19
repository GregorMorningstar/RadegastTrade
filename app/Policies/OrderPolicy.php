<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Support\OrderVisibility;
use App\Support\TradeAccess;

/**
 * Who sees orders (orders.view): see OrderVisibility. Placing and changing the status are decided by OrderService,
 * which also checks the offer, the cooperation and the unit.
 */
class OrderPolicy
{
    public function __construct(private readonly TradeAccess $access, private readonly OrderVisibility $visibility)
    {
        //
    }

    public function viewAny(User $user): bool
    {
        return $this->access->canTrade($user);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->visibility->any($user)->whereKey($order->id)->exists();
    }
}
