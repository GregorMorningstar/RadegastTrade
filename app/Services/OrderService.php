<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferShare;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\TradeRelationshipUnit;
use App\Models\User;
use App\Notifications\OrderNotification;
use App\Support\OfferVisibility;
use App\Support\OrderVisibility;
use App\Support\TradeAccess;
use App\Support\UnitRef;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Placing and handling orders. The server decides everything that matters: who may order for the unit, whether the
 * offer is open to that unit, which version it is, and the prices (taken from the frozen version, never from the request).
 * The order keeps a copy of what was ordered, so later changes of the offer do not touch it.
 */
class OrderService
{
    public function __construct(
        private readonly TradeAccess $access,
        private readonly OfferVisibility $offers,
        private readonly OrderVisibility $visibility,
        private readonly AuditLogger $audit,
    ) {
        //
    }

    /**
     * Places an order for a unit. The same idempotency key from the same unit returns the order already placed.
     *
     * @param  array{offer_id: int, offer_version_id: int, buyer_unit: string, items: array<int, array{offer_item_id: int, qty: string|int|float}>, delivery_address: array<string, string>, notes?: string|null, idempotency_key: string}  $data
     */
    public function place(User $user, array $data): Order
    {
        abort_unless($this->access->canTrade($user), 403);

        $buyer = UnitRef::parse($data['buyer_unit'] ?? null);

        // the right to order is checked against the person, not against what the form says
        abort_if($buyer === null || ! $this->visibility->canPlaceFor($user, $buyer), 403);

        $existing = Order::query()->where('buyer_unit_ref', $buyer->ref())->where('idempotency_key', $data['idempotency_key'])->first();

        if ($existing !== null) {
            return $this->sameRequest($user, $existing);
        }

        $offer = Offer::query()->find((int) $data['offer_id']);

        // an offer the person cannot see does not exist for them
        abort_if($offer === null || ! $this->offers->canView($user, $offer), 404);

        try {
            return DB::transaction(function () use ($user, $buyer, $offer, $data) {
                $offer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail()->load('currentVersion.items');

                $relationshipId = $this->route($offer, $buyer, $user);

                if ((int) $data['offer_version_id'] !== $offer->current_version_id) {
                    throw ValidationException::withMessages(['offer' => 'Oferta została zmieniona. Odśwież stronę i złóż zamówienie ponownie.']);
                }

                $version = $offer->currentVersion;
                [$lines, $subtotal, $tax] = $this->lines($version->items->keyBy('id'), $data['items'] ?? []);

                $address = $this->address($data['delivery_address'] ?? []);
                $seller = $offer->unit();
                $now = now();

                $order = Order::create([
                    'number' => 'tmp-'.Str::random(20),
                    'buyer_organization_id' => $user->organization_id,
                    'buyer_unit_type' => $buyer->type->value,
                    'buyer_unit_level' => $buyer->level->value,
                    'buyer_unit_id' => $buyer->id,
                    'buyer_unit_ref' => $buyer->ref(),
                    'buyer_unit_name' => (string) $buyer->find((int) $user->organization_id)?->name,
                    'seller_organization_id' => $offer->organization_id,
                    'seller_unit_type' => $seller->type->value,
                    'seller_unit_level' => $seller->level->value,
                    'seller_unit_id' => $seller->id,
                    'seller_unit_ref' => $seller->ref(),
                    'seller_unit_name' => (string) $seller->find($offer->organization_id)?->name,
                    'offer_id' => $offer->id,
                    'offer_version_id' => $version->id,
                    'relationship_id' => $relationshipId,
                    'placed_by' => $user->id,
                    'placed_by_name' => $user->name,
                    'status' => OrderStatus::Submitted->value,
                    'currency' => $version->currency,
                    'subtotal' => (string) $subtotal,
                    'tax_total' => (string) $tax,
                    'total' => (string) $subtotal->plus($tax),
                    'delivery_address' => $address,
                    'notes' => isset($data['notes']) ? Str::limit(trim((string) $data['notes']), 1000, '') : null,
                    'idempotency_key' => $data['idempotency_key'],
                    'placed_at' => $now,
                    'status_changed_at' => $now,
                ]);

                $order->update(['number' => sprintf('ZAM/%s/%06d', $now->format('Y'), $order->id)]);

                foreach ($lines as $line) {
                    OrderItem::create(['order_id' => $order->id, ...$line]);
                }

                OrderStatusHistory::create(['order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Submitted->value, 'changed_by' => $user->id, 'created_at' => $now]);

                $this->audit->record($user, $user->organization_id, 'order.placed', $order, ['offer' => $offer->number, 'version' => $version->version_no, 'total' => (string) $order->total, 'buyer_unit' => $buyer->ref()]);

                DB::afterCommit(fn () => $this->notifyManagers($seller, $offer->organization_id, $order, 'Nowe zamówienie do potwierdzenia', 'Złożył(a): '.$user->name.', jednostka: '.$order->buyer_unit_name.'.'));

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            // the same request twice at once: the unique key let only one in, so the second gets that order
            return $this->sameRequest($user, Order::query()->where('buyer_unit_ref', $buyer->ref())->where('idempotency_key', $data['idempotency_key'])->firstOrFail());
        }
    }

    /**
     * Changes the status of an order. The seller confirms, rejects and fulfils (the owner and the managers of the selling
     * unit); the buyer cancels until the order is fulfilled and confirms the receipt (whoever may order for the unit).
     */
    public function transition(User $user, Order $order, OrderStatus $to, ?string $reason = null): Order
    {
        Gate::forUser($user)->authorize('view', $order);

        return DB::transaction(function () use ($user, $order, $to, $reason) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $side = $order->status->transitions()[$to->value] ?? null;

            if ($side === null) {
                throw ValidationException::withMessages(['status' => 'Ze statusu „'.$order->status->label().'” nie można przejść do „'.$to->label().'”.']);
            }

            $allowed = $side === 'seller'
                ? $this->visibility->isSeller($user, $order)
                : ($order->buyerUnit() !== null && $this->visibility->canPlaceFor($user, $order->buyerUnit()));

            abort_unless($allowed, 403);

            $reason = $reason === null ? null : Str::limit(trim($reason), 500, '');

            if ($to === OrderStatus::Rejected && ($reason === null || $reason === '')) {
                throw ValidationException::withMessages(['reason' => 'Podaj powód odrzucenia zamówienia.']);
            }

            $from = $order->status;
            $order->update(['status' => $to->value, 'status_changed_at' => now()]);

            OrderStatusHistory::create(['order_id' => $order->id, 'from_status' => $from->value, 'to_status' => $to->value, 'changed_by' => $user->id, 'reason' => $reason, 'created_at' => now()]);
            $this->audit->record($user, $user->organization_id, 'order.'.$to->value, $order, ['from' => $from->value, 'reason' => $reason]);

            DB::afterCommit(function () use ($order, $side, $to, $reason) {
                $headline = 'Zamówienie: '.$to->label();

                if ($side === 'seller') {
                    $this->notifyBuyer($order, $headline, (string) $reason);
                } else {
                    $this->notifyManagers($order->sellerUnit(), $order->seller_organization_id, $order, $headline, (string) $reason);
                }
            });

            return $order;
        });
    }

    /**
     * Units the person may order this offer for right now: units of their organization the offer is open to (the whole
     * organization for an offer published inside it, the units it was shared with and that may order for another one)
     * for which the person may place orders.
     *
     * @return list<array{ref: string, name: string}>
     */
    public function orderableUnits(User $user, Offer $offer): array
    {
        if (! $this->access->canTrade($user) || ! $offer->loadMissing('currentVersion')->isOpen()) {
            return [];
        }

        $organizationId = (int) $user->organization_id;
        $candidates = [];

        if ($offer->organization_id === $organizationId && $offer->shared_internally) {
            $candidates = array_map(fn (UnitRef $unit) => $unit->ref(), $this->access->allUnits($organizationId));
        }

        $shares = OfferShare::query()
            ->where('offer_id', $offer->id)
            ->where('recipient_organization_id', $organizationId)
            ->where('allow_order', true)
            ->whereNull('revoked_at')
            ->get();

        foreach ($shares as $share) {
            if ($this->cooperationAllows($offer, $share)) {
                $candidates[] = $share->unit_ref;
            }
        }

        return collect(array_unique($candidates))
            ->reject(fn (string $ref) => $ref === $offer->unit_ref)
            ->map(fn (string $ref) => UnitRef::parse($ref))
            ->filter(fn (?UnitRef $unit) => $unit !== null && $this->visibility->canPlaceFor($user, $unit))
            ->map(fn (UnitRef $unit) => ['ref' => $unit->ref(), 'name' => (string) $unit->find($organizationId)?->name])
            ->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $user, string $kind, ?string $status = null): array
    {
        Gate::forUser($user)->authorize('viewAny', Order::class);

        $query = $kind === 'sales' ? $this->visibility->sales($user) : $this->visibility->purchases($user);

        return $query
            ->when(OrderStatus::tryFrom((string) $status) !== null, fn ($builder) => $builder->where('status', $status))
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (Order $order) => $this->summary($order, $kind))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, Order $order): array
    {
        Gate::forUser($user)->authorize('view', $order);

        $order->load(['items', 'history.changedBy:id,name']);
        $isSeller = $this->visibility->isSeller($user, $order);
        $isBuyer = $this->visibility->isBuyer($user, $order);

        $actions = [];

        foreach ($order->status->transitions() as $to => $side) {
            $may = $side === 'seller'
                ? $isSeller
                : ($order->buyerUnit() !== null && $this->visibility->canPlaceFor($user, $order->buyerUnit()));

            if ($may) {
                $actions[] = ['to' => $to, 'label' => OrderStatus::from($to)->label(), 'needs_reason' => $to === OrderStatus::Rejected->value];
            }
        }

        return [
            ...$this->summary($order, $isSeller ? 'sales' : 'purchases'),
            'is_seller' => $isSeller,
            'is_buyer' => $isBuyer,
            'subtotal' => $order->subtotal,
            'tax_total' => $order->tax_total,
            'delivery_address' => $order->delivery_address,
            'notes' => $order->notes,
            'offer_version' => $order->version?->version_no,
            'actions' => $actions,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'unit_of_measure' => $item->unit_of_measure,
                'qty' => $item->qty,
                'unit_price' => $item->unit_price,
                'vat_rate' => $item->vat_rate,
                'line_net' => $item->line_net,
                'line_gross' => $item->line_gross,
            ])->values()->all(),
            'history' => $order->history->map(fn (OrderStatusHistory $row) => [
                'from' => $row->from_status?->label(),
                'to' => $row->to_status->label(),
                'by' => $row->changedBy?->name,
                'reason' => $row->reason,
                'at' => $row->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Order $order, string $kind): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'currency' => $order->currency,
            'total' => $order->total,
            'buyer_unit' => $order->buyer_unit_name,
            'seller_unit' => $order->seller_unit_name,
            'placed_by' => $order->placed_by_name,
            'placed_at' => $order->placed_at->toIso8601String(),
            'kind' => $kind,
        ];
    }

    /**
     * Checks that the offer is open to the buyer unit and returns the cooperation it goes through (null for an offer
     * published inside the organization). Anything else is refused with a reason that reveals nothing about others.
     */
    private function route(Offer $offer, UnitRef $buyer, User $user): ?int
    {
        $offer->loadMissing('currentVersion');
        $seller = $offer->unit();

        if (! $offer->isOpen()) {
            throw ValidationException::withMessages(['offer' => 'Ta oferta jest nieaktualna: wycofana, wygasła albo jeszcze nie obowiązuje.']);
        }

        if ($seller === null || $seller->equals($buyer)) {
            throw ValidationException::withMessages(['buyer_unit' => 'Jednostka nie może zamawiać od siebie.']);
        }

        if ($offer->organization_id === (int) $user->organization_id) {
            if (! $offer->shared_internally) {
                throw ValidationException::withMessages(['offer' => 'Ta oferta nie jest udostępniona w organizacji.']);
            }

            return null;
        }

        $share = OfferShare::query()
            ->where('offer_id', $offer->id)
            ->where('unit_ref', $buyer->ref())
            ->where('recipient_organization_id', $user->organization_id)
            ->whereNull('revoked_at')
            ->first();

        if ($share === null || ! $share->allow_order) {
            throw ValidationException::withMessages(['offer' => 'Ta oferta nie jest dostępna do zamawiania dla wybranej jednostki.']);
        }

        if (! $this->cooperationAllows($offer, $share)) {
            throw ValidationException::withMessages(['offer' => 'Współpraca z dostawcą jest zawieszona lub zakończona. Nowych zamówień nie można składać.']);
        }

        return $share->relationship_id;
    }

    /** Whether the cooperation of a share is active and still has both units in its scope. */
    private function cooperationAllows(Offer $offer, OfferShare $share): bool
    {
        $relationship = $share->relationship;

        if ($relationship === null || ! $relationship->isActive() || $relationship->supplier_organization_id !== $offer->organization_id) {
            return false;
        }

        $inScope = fn (string $side, string $ref) => TradeRelationshipUnit::query()
            ->where('relationship_id', $relationship->id)->where('side', $side)->where('unit_ref', $ref)->whereNull('revoked_at')->exists();

        return $inScope('buyer', $share->unit_ref) && $inScope('supplier', $offer->unit_ref);
    }

    /**
     * The positions of the order with their amounts, worked out on the server from the frozen offer version.
     *
     * @param  Collection<int, OfferItem>  $offerItems
     * @param  array<int, array{offer_item_id: int, qty: string|int|float}>  $requested
     * @return array{0: list<array<string, mixed>>, 1: BigDecimal, 2: BigDecimal}
     */
    private function lines($offerItems, array $requested): array
    {
        if ($requested === []) {
            throw ValidationException::withMessages(['items' => 'Zamówienie musi mieć co najmniej jedną pozycję.']);
        }

        $lines = [];
        $seen = [];
        $subtotal = BigDecimal::zero();
        $tax = BigDecimal::zero();

        foreach ($requested as $row) {
            $item = $offerItems->get((int) ($row['offer_item_id'] ?? 0));

            // a position of another offer (or version) is not in this collection
            if ($item === null || isset($seen[$item->id])) {
                throw ValidationException::withMessages(['items' => 'Zamówienie zawiera nieprawidłową lub powtórzoną pozycję.']);
            }

            $seen[$item->id] = true;

            try {
                $qty = BigDecimal::of((string) ($row['qty'] ?? ''))->toScale(3, RoundingMode::Unnecessary);
            } catch (MathException) {
                throw ValidationException::withMessages(['items' => 'Nieprawidłowa ilość dla „'.$item->name.'”.']);
            }

            $min = BigDecimal::of($item->min_qty);
            $step = BigDecimal::of($item->qty_step);

            if ($qty->isLessThanOrEqualTo(0) || $qty->isLessThan($min)
                || ($item->max_qty !== null && $qty->isGreaterThan(BigDecimal::of($item->max_qty)))
                || ! $qty->minus($min)->remainder($step)->isZero()) {
                throw ValidationException::withMessages(['items' => 'Ilość dla „'.$item->name.'” jest niedozwolona (minimum, maksimum lub wielokrotność).']);
            }

            $net = BigDecimal::of($item->unit_price)->multipliedBy($qty)->toScale(2, RoundingMode::HalfUp);
            $lineTax = $item->vat_rate === null ? BigDecimal::zero() : $net->multipliedBy(BigDecimal::of($item->vat_rate))->dividedBy(100, 2, RoundingMode::HalfUp);

            $subtotal = $subtotal->plus($net);
            $tax = $tax->plus($lineTax);

            $lines[] = [
                'offer_item_id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'unit_of_measure' => $item->unit_of_measure,
                'qty' => (string) $qty,
                'unit_price' => $item->unit_price,
                'vat_rate' => $item->vat_rate,
                'line_net' => (string) $net,
                'line_tax' => (string) $lineTax,
                'line_gross' => (string) $net->plus($lineTax),
            ];
        }

        return [$lines, $subtotal, $tax];
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, string>
     */
    private function address(array $address): array
    {
        $clean = [];

        foreach (['name', 'street', 'postal_code', 'city', 'country', 'phone'] as $field) {
            $clean[$field] = Str::limit(trim((string) ($address[$field] ?? '')), 150, '');
        }

        foreach (['name', 'street', 'postal_code', 'city'] as $required) {
            if ($clean[$required] === '') {
                throw ValidationException::withMessages(['delivery_address' => 'Uzupełnij adres dostawy.']);
            }
        }

        return $clean;
    }

    private function sameRequest(User $user, Order $order): Order
    {
        // the key belongs to the person's own request; somebody else's key is just refused
        if ($order->placed_by !== $user->id) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ten identyfikator żądania jest już użyty.']);
        }

        return $order;
    }

    private function notifyManagers(?UnitRef $unit, int $organizationId, Order $order, string $headline, string $detail): void
    {
        if ($unit === null) {
            return;
        }

        foreach ($this->access->managersOf($unit, $organizationId) as $manager) {
            $manager->notify(new OrderNotification($order->id, $order->number, $headline, $detail));
        }
    }

    private function notifyBuyer(Order $order, string $headline, string $detail): void
    {
        $placer = $order->placed_by === null ? null : User::query()->where('is_active', true)->find($order->placed_by);

        if ($placer !== null) {
            $placer->notify(new OrderNotification($order->id, $order->number, $headline, $detail));

            return;
        }

        $this->notifyManagers($order->buyerUnit(), $order->buyer_organization_id, $order, $headline, $detail);
    }
}
