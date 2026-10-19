<?php

namespace App\Http\Controllers\Trade;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\OfferAttachment;
use App\Services\OfferService;
use App\Support\OfferVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Own offers of the units the person manages: drafts, publishing, sharing, versions and attachments. An offer the person
 * cannot see is a 404 (no hint that it exists); one they can see but not manage is a 403 from the policy.
 */
class OfferController extends Controller
{
    public function __construct(private readonly OfferService $offers, private readonly OfferVisibility $visibility)
    {
        //
    }

    public function index(Request $request): Response
    {
        $status = $request->query('status');

        return Inertia::render('trade/offers/index', [
            'offers' => $this->offers->ownList($request->user(), is_string($status) ? $status : null),
            'status' => is_string($status) ? $status : null,
            'canCreate' => Gate::forUser($request->user())->allows('create', Offer::class),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('create', Offer::class);

        return Inertia::render('trade/offers/edit', ['offer' => null, 'units' => $this->offers->sellerUnits($request->user())]);
    }

    public function store(Request $request): RedirectResponse
    {
        // the right is checked before the data is looked at, so a person without it learns nothing from the validation
        Gate::forUser($request->user())->authorize('create', Offer::class);

        $offer = $this->offers->create($request->user(), [...$this->validated($request), 'unit_ref' => (string) $request->input('unit_ref')]);

        return to_route('trade.offers.show', $offer)->with('status', 'offer-created');
    }

    public function show(Request $request, int $offer): Response
    {
        $model = $this->find($request, $offer);

        return Inertia::render('trade/offers/show', ['offer' => $this->offers->show($request->user(), $model)]);
    }

    public function edit(Request $request, int $offer): Response
    {
        $model = $this->find($request, $offer);
        Gate::forUser($request->user())->authorize('manage', $model);

        return Inertia::render('trade/offers/edit', ['offer' => $this->offers->show($request->user(), $model), 'units' => []]);
    }

    public function update(Request $request, int $offer): RedirectResponse
    {
        $model = $this->find($request, $offer);
        Gate::forUser($request->user())->authorize('manage', $model);

        $this->offers->updateDraft($request->user(), $model, $this->validated($request));

        return to_route('trade.offers.show', $offer);
    }

    public function publish(Request $request, int $offer): RedirectResponse
    {
        $data = $request->validate(['shared_internally' => ['nullable', 'boolean']]);

        $this->offers->publish($request->user(), $this->find($request, $offer), array_key_exists('shared_internally', $data) ? (bool) $data['shared_internally'] : null);

        return to_route('trade.offers.show', $offer);
    }

    public function internal(Request $request, int $offer): RedirectResponse
    {
        $data = $request->validate(['shared_internally' => ['required', 'boolean']]);

        $this->offers->setInternal($request->user(), $this->find($request, $offer), (bool) $data['shared_internally']);

        return back();
    }

    public function withdraw(Request $request, int $offer): RedirectResponse
    {
        $this->offers->withdraw($request->user(), $this->find($request, $offer));

        return back();
    }

    public function archive(Request $request, int $offer): RedirectResponse
    {
        $this->offers->archive($request->user(), $this->find($request, $offer));

        return to_route('trade.offers.index');
    }

    public function share(Request $request, int $offer): RedirectResponse
    {
        $data = $request->validate([
            'units' => ['required', 'array', 'min:1', 'max:200'],
            'units.*' => ['required', 'string', 'max:40'],
            'allow_order' => ['nullable', 'boolean'],
        ]);

        $this->offers->share($request->user(), $this->find($request, $offer), $data['units'], (bool) ($data['allow_order'] ?? true));

        return back();
    }

    public function revokeShare(Request $request, int $offer, int $share): RedirectResponse
    {
        $model = $this->find($request, $offer);

        $this->offers->revokeShare($request->user(), $model, $model->shares()->findOrFail($share));

        return back();
    }

    public function attach(Request $request, int $offer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx,docx', 'max:5120']], [
            'file.mimes' => 'Dozwolone formaty: PDF, JPG, PNG, XLSX, DOCX.',
            'file.max' => 'Załącznik może mieć najwyżej 5 MB.',
        ]);

        $this->offers->attach($request->user(), $this->find($request, $offer), $request->file('file'));

        return back();
    }

    public function removeAttachment(Request $request, int $offer, int $attachment): RedirectResponse
    {
        $model = $this->find($request, $offer);
        $file = OfferAttachment::query()->whereHas('version', fn ($version) => $version->where('offer_id', $model->id))->findOrFail($attachment);

        $this->offers->removeAttachment($request->user(), $model, $file);

        return back();
    }

    public function download(Request $request, int $attachment): StreamedResponse
    {
        return $this->offers->download($request->user(), OfferAttachment::query()->findOrFail($attachment));
    }

    private function find(Request $request, int $id): Offer
    {
        return $this->visibility->query($request->user())->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'delivery_terms' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.name' => ['required', 'string', 'max:200'],
            'items.*.sku' => ['nullable', 'string', 'max:64'],
            'items.*.barcode' => ['nullable', 'string', 'max:13'],
            'items.*.unit_of_measure' => ['required', 'string', 'max:16'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.min_qty' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.qty_step' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.max_qty' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
        ]);
    }
}
