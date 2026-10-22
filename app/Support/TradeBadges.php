<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The bubbles in the menu of the trade module: how many orders and offers are new for the person, that is, came (or changed,
 * for an order) after they last opened the list, and are not their own doing. Which orders and offers are counted is
 * decided by the visibility classes, so a bubble never counts what the person could not open.
 */
class TradeBadges
{
    public function __construct(
        private readonly TradeAccess $access,
        private readonly OrderVisibility $orders,
        private readonly OfferVisibility $offers,
    ) {
        //
    }

    /** @return array{orders: int, offers: int} */
    public function counts(User $user): array
    {
        if (! $this->access->canTrade($user)) {
            return ['orders' => 0, 'offers' => 0];
        }

        $orders = $this->seen($user, 'orders');
        $offers = $this->seen($user, 'offers');

        return [
            // a new order of somebody else, or a change of the status made by somebody else
            'orders' => $this->orders->any($user)
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $new) => $new->where('orders.created_at', '>', $orders)
                        ->where(fn (Builder $other) => $other->whereNull('orders.placed_by')->orWhere('orders.placed_by', '!=', $user->id)))
                    ->orWhereHas('history', fn (Builder $history) => $history->where('created_at', '>', $orders)
                        ->where(fn (Builder $other) => $other->whereNull('changed_by')->orWhere('changed_by', '!=', $user->id))))
                ->count(),
            // an offer published after the last visit, not by the person
            'offers' => $this->offers->query($user)
                ->where('offers.status', 'published')->where('offers.published_at', '>', $offers)
                ->where(fn (Builder $other) => $other->whereNull('offers.created_by')->orWhere('offers.created_by', '!=', $user->id))
                ->count(),
        ];
    }

    /** The person has opened the list ("orders" or "offers"): what is there now is no longer new. */
    public function markSeen(User $user, string $what): void
    {
        Cache::forever($this->key($user, $what), now()->getTimestamp());
    }

    private function seen(User $user, string $what): CarbonImmutable
    {
        $timestamp = Cache::get($this->key($user, $what));

        return $timestamp === null
            ? CarbonImmutable::instance($user->created_at ?? now())
            : CarbonImmutable::createFromTimestamp((int) $timestamp);
    }

    private function key(User $user, string $what): string
    {
        return 'trade-seen:'.$user->id.':'.$what;
    }
}
