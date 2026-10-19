<?php

namespace App\Http\Controllers\Trade;

use App\Http\Controllers\Controller;
use App\Services\OfferService;
use App\Support\OfferVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Offers available to the person: published inside the organization and shared with their units. Ordering is a separate
 * action (OrderController); looking at an offer never gives the right to order.
 */
class AvailableOfferController extends Controller
{
    public function __construct(private readonly OfferService $offers, private readonly OfferVisibility $visibility)
    {
        //
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'supplier' => ['nullable', 'integer'],
            'unit' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return Inertia::render('trade/available/index', [...$this->offers->availableList($request->user(), $filters), 'filters' => $filters]);
    }

    public function show(Request $request, int $offer): Response
    {
        $model = $this->visibility->query($request->user())->findOrFail($offer);

        return Inertia::render('trade/available/show', [
            'offer' => $this->offers->show($request->user(), $model),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }
}
