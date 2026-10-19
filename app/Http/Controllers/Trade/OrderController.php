<?php

namespace App\Http\Controllers\Trade;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Services\OrderService;
use App\Support\OrderVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Orders: purchases (the buyer side) and received orders (the seller side). An order the person cannot see is a 404.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders, private readonly OrderVisibility $visibility)
    {
        //
    }

    public function index(Request $request): Response
    {
        $kind = $request->query('kind') === 'sales' ? 'sales' : 'purchases';
        $status = $request->query('status');

        return Inertia::render('trade/orders/index', [
            'kind' => $kind,
            'status' => is_string($status) ? $status : null,
            'orders' => $this->orders->list($request->user(), $kind, is_string($status) ? $status : null),
        ]);
    }

    public function show(Request $request, int $order): Response
    {
        $model = $this->visibility->any($request->user())->findOrFail($order);

        return Inertia::render('trade/orders/show', ['order' => $this->orders->show($request->user(), $model)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'offer_id' => ['required', 'integer'],
            'offer_version_id' => ['required', 'integer'],
            'buyer_unit' => ['required', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.offer_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'delivery_address' => ['required', 'array'],
            'delivery_address.name' => ['required', 'string', 'max:150'],
            'delivery_address.street' => ['required', 'string', 'max:150'],
            'delivery_address.postal_code' => ['required', 'string', 'max:150'],
            'delivery_address.city' => ['required', 'string', 'max:150'],
            'delivery_address.country' => ['nullable', 'string', 'max:150'],
            'delivery_address.phone' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        // the price, the unit price and the totals are never read from the request: only the positions and quantities
        $order = $this->orders->place($request->user(), $data);

        return to_route('trade.orders.show', $order)->with('status', 'order-placed');
    }

    public function transition(Request $request, int $order): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $to = OrderStatus::tryFrom($data['to']);
        $model = $this->visibility->any($request->user())->findOrFail($order);

        abort_if($to === null, 422);

        $this->orders->transition($request->user(), $model, $to, $data['reason'] ?? null);

        return back();
    }
}
