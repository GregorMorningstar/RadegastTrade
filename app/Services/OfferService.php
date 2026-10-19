<?php

namespace App\Services;

use App\Enums\OfferStatus;
use App\Enums\OfferVersionStatus;
use App\Models\Offer;
use App\Models\OfferAttachment;
use App\Models\OfferItem;
use App\Models\OfferShare;
use App\Models\OfferVersion;
use App\Models\Organization;
use App\Models\TradeRelationshipUnit;
use App\Models\User;
use App\Notifications\OfferSharedNotification;
use App\Support\OfferVisibility;
use App\Support\TradeAccess;
use App\Support\UnitRef;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trade offers: drafts and versions, publishing, withdrawing, sharing inside the organization and with units of
 * partner organizations, attachments and what each person is allowed to see.
 *
 * A published version is frozen (see OfferVersion); a change creates a new draft version. Prices and conditions are
 * copied into the offer's positions, never read from the product record later.
 */
class OfferService
{
    public const MAX_ATTACHMENTS = 10;

    public function __construct(
        private readonly TradeAccess $access,
        private readonly OfferVisibility $visibility,
        private readonly OrderService $orders,
        private readonly AuditLogger $audit,
    ) {
        //
    }

    /**
     * Creates an offer with its first draft version.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Offer
    {
        Gate::forUser($user)->authorize('create', Offer::class);

        $unit = UnitRef::parse($data['unit_ref'] ?? null);

        if ($unit === null || ! $unit->isWarehouse() || $unit->find((int) $user->organization_id) === null || ! $this->access->manages($user, $unit)) {
            throw ValidationException::withMessages(['unit_ref' => 'Wybierz hurtownię, którą zarządzasz. Oferty przedstawiają tylko hurtownie.']);
        }

        return DB::transaction(function () use ($user, $unit, $data) {
            // one number sequence per organization: the organization row is the lock that keeps it gap- and duplicate-free
            Organization::query()->whereKey($user->organization_id)->lockForUpdate()->first();
            $number = sprintf('OF/%s/%05d', now()->format('Y'), Offer::query()->where('organization_id', $user->organization_id)->count() + 1);

            $offer = Offer::create([
                'organization_id' => $user->organization_id,
                'number' => $number,
                'unit_ref' => $unit->ref(),
                'created_by' => $user->id,
                'status' => OfferStatus::Draft->value,
                ...$unit->columns(),
            ]);

            $version = $this->newDraft($offer, 1, $user);
            $this->fillDraft($version, $data);

            $this->audit->record($user, $user->organization_id, 'offer.created', $offer, ['number' => $number]);

            return $offer;
        });
    }

    /**
     * Changes the draft of an offer; for a published offer this opens a new draft (version) copied from the published one.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(User $user, Offer $offer, array $data): OfferVersion
    {
        Gate::forUser($user)->authorize('manage', $offer);
        $this->ensureEditable($offer);

        return DB::transaction(function () use ($user, $offer, $data) {
            $version = $this->ensureDraft($offer, $user);
            $this->fillDraft($version, $data);
            $this->audit->record($user, $user->organization_id, 'offer.draft_updated', $offer, ['version' => $version->version_no]);

            return $version;
        });
    }

    /**
     * Freezes the draft as the published version. The previous published version stays for history (superseded), and
     * the shares of the offer carry on with the new version.
     */
    public function publish(User $user, Offer $offer, ?bool $sharedInternally = null): Offer
    {
        Gate::forUser($user)->authorize('manage', $offer);
        $this->ensureEditable($offer);

        return DB::transaction(function () use ($user, $offer, $sharedInternally) {
            $offer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $draft = $offer->versions()->where('status', OfferVersionStatus::Draft->value)->first();

            if ($draft === null) {
                throw ValidationException::withMessages(['offer' => 'Nie ma wersji roboczej do opublikowania.']);
            }

            $this->assertPublishable($draft);

            if ($offer->current_version_id !== null) {
                OfferVersion::query()->whereKey($offer->current_version_id)->first()?->update(['status' => OfferVersionStatus::Superseded->value]);
            }

            $draft->update([
                'status' => OfferVersionStatus::Published->value,
                'draft_key' => null,
                'published_at' => now(),
                'published_by' => $user->id,
                'content_hash' => $this->contentHash($draft),
            ]);

            $offer->update([
                'status' => OfferStatus::Published->value,
                'current_version_id' => $draft->id,
                'published_at' => $offer->published_at ?? now(),
                'withdrawn_at' => null,
                'withdrawn_by' => null,
                'shared_internally' => $sharedInternally ?? $offer->shared_internally,
            ]);

            $this->audit->record($user, $user->organization_id, 'offer.published', $offer, ['version' => $draft->version_no, 'internal' => $offer->shared_internally]);

            return $offer->refresh();
        });
    }

    public function setInternal(User $user, Offer $offer, bool $shared): void
    {
        Gate::forUser($user)->authorize('manage', $offer);
        $this->ensureEditable($offer);

        $offer->update(['shared_internally' => $shared]);
        $this->audit->record($user, $user->organization_id, 'offer.internal_visibility', $offer, ['shared' => $shared]);
    }

    public function withdraw(User $user, Offer $offer): void
    {
        Gate::forUser($user)->authorize('manage', $offer);

        if ($offer->status !== OfferStatus::Published) {
            throw ValidationException::withMessages(['offer' => 'Wycofać można tylko opublikowaną ofertę.']);
        }

        $offer->update(['status' => OfferStatus::Withdrawn->value, 'withdrawn_at' => now(), 'withdrawn_by' => $user->id]);
        $this->audit->record($user, $user->organization_id, 'offer.withdrawn', $offer);
    }

    public function archive(User $user, Offer $offer): void
    {
        Gate::forUser($user)->authorize('manage', $offer);

        if ($offer->status === OfferStatus::Published && ! $offer->isExpired()) {
            throw ValidationException::withMessages(['offer' => 'Najpierw wycofaj ofertę, potem ją zarchiwizuj.']);
        }

        $offer->update(['status' => OfferStatus::Archived->value]);
        $this->audit->record($user, $user->organization_id, 'offer.archived', $offer);
    }

    /**
     * Shares the published offer with units of partner organizations. Each unit has to be in the scope of an active
     * cooperation in which the organization of the offer is the supplier and the unit presenting the offer is in the
     * scope too; otherwise the share is refused. The e-mail to the managers of the unit only informs.
     *
     * @param  array<int, string>  $unitRefs
     */
    public function share(User $user, Offer $offer, array $unitRefs, bool $allowOrder = true): void
    {
        Gate::forUser($user)->authorize('manage', $offer);

        if ($offer->status !== OfferStatus::Published || $offer->isExpired()) {
            throw ValidationException::withMessages(['units' => 'Udostępnić można tylko opublikowaną, ważną ofertę.']);
        }

        $refs = array_values(array_unique(array_filter($unitRefs, 'is_string')));

        if ($refs === []) {
            throw ValidationException::withMessages(['units' => 'Wybierz co najmniej jedną jednostkę odbiorcy.']);
        }

        $notify = [];

        DB::transaction(function () use ($user, $offer, $refs, $allowOrder, &$notify) {
            foreach ($refs as $ref) {
                $recipient = UnitRef::parse($ref) === null ? null : $this->cooperationUnit($offer, $ref);

                if ($recipient === null) {
                    throw ValidationException::withMessages(['units' => 'Jedna z jednostek nie należy do zakresu aktywnej współpracy z tą ofertą.']);
                }

                $key = $offer->id.':'.$ref;
                $share = OfferShare::query()->where('active_key', $key)->first();

                if ($share !== null) {
                    $share->update(['allow_order' => $allowOrder]);

                    continue;
                }

                $unit = UnitRef::parse($ref);
                $share = OfferShare::create([
                    'offer_id' => $offer->id,
                    'relationship_id' => $recipient->relationship_id,
                    'recipient_organization_id' => $recipient->organization_id,
                    'unit_ref' => $ref,
                    'allow_order' => $allowOrder,
                    'shared_by' => $user->id,
                    'shared_at' => now(),
                    'active_key' => $key,
                    ...$unit->columns(),
                ]);

                $notify[] = [$share, $unit, $recipient->organization_id];
                $this->audit->record($user, $user->organization_id, 'offer.shared', $offer, ['unit' => $ref, 'recipient_organization_id' => $recipient->organization_id]);
            }
        });

        $version = $offer->currentVersion;
        $supplier = (string) $offer->organization?->name;

        foreach ($notify as [$share, $unit, $organizationId]) {
            $unitName = (string) $unit->find($organizationId)?->name;

            foreach ($this->access->managersOf($unit, $organizationId) as $manager) {
                $manager->notify(new OfferSharedNotification($offer->number, (string) $version?->title, $supplier, $unitName));
            }

            $share->update(['notified_at' => now()]);
        }
    }

    public function revokeShare(User $user, Offer $offer, OfferShare $share): void
    {
        Gate::forUser($user)->authorize('manage', $offer);

        abort_unless($share->offer_id === $offer->id && $share->revoked_at === null, 404);

        $share->update(['revoked_at' => now(), 'revoked_by' => $user->id, 'active_key' => null]);
        $this->audit->record($user, $user->organization_id, 'offer.share_revoked', $offer, ['unit' => $share->unit_ref]);
    }

    public function attach(User $user, Offer $offer, UploadedFile $file): OfferAttachment
    {
        Gate::forUser($user)->authorize('manage', $offer);
        $this->ensureEditable($offer);

        return DB::transaction(function () use ($user, $offer, $file) {
            $version = $this->ensureDraft($offer, $user);

            if ($version->attachments()->count() >= self::MAX_ATTACHMENTS) {
                throw ValidationException::withMessages(['file' => 'Oferta może mieć najwyżej '.self::MAX_ATTACHMENTS.' załączników.']);
            }

            $path = $file->storeAs('offer-attachments/'.$offer->id, Str::random(40).'.'.$file->guessExtension(), 'local');

            return OfferAttachment::create([
                'offer_version_id' => $version->id,
                'path' => $path,
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 150, ''),
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => $user->id,
            ]);
        });
    }

    public function removeAttachment(User $user, Offer $offer, OfferAttachment $attachment): void
    {
        Gate::forUser($user)->authorize('manage', $offer);

        $version = $attachment->version;

        abort_unless($version !== null && $version->offer_id === $offer->id && $version->isDraft(), 404);

        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();
    }

    /**
     * Sends an attachment to a person who may see its offer. A draft's file only to the managers of the offer; the file
     * of a frozen version to everyone who may look at the offer. The path is never exposed.
     */
    public function download(User $user, OfferAttachment $attachment): StreamedResponse
    {
        $version = $attachment->version;
        $offer = $version?->offer;

        abort_if($offer === null, 404);
        abort_unless($this->visibility->canView($user, $offer), 404);

        if ($version->isDraft()) {
            abort_unless(Gate::forUser($user)->allows('manage', $offer), 404);
        }

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        $this->audit->record($user, $user->organization_id, 'offer.attachment_downloaded', $offer, ['attachment' => $attachment->id]);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * Own offers (those of the units the person manages) with the status filter.
     *
     * @return list<array<string, mixed>>
     */
    public function ownList(User $user, ?string $status = null): array
    {
        Gate::forUser($user)->authorize('viewAny', Offer::class);

        $managed = array_map(fn (UnitRef $unit) => $unit->ref(), $this->access->managedUnits($user));

        return Offer::query()
            ->where('organization_id', $user->organization_id)
            ->whereIn('unit_ref', $managed)
            ->with(['currentVersion', 'organization:id,name'])
            ->when($status === 'expired', fn (Builder $query) => $query->where('status', 'published')->whereHas('currentVersion', fn (Builder $version) => $version->where('valid_to', '<', now())))
            ->when(in_array($status, ['draft', 'withdrawn', 'archived'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($status === 'published', fn (Builder $query) => $query->where('status', 'published')->whereHas('currentVersion', fn (Builder $version) => $version->where(fn (Builder $open) => $open->whereNull('valid_to')->orWhere('valid_to', '>=', now()))))
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (Offer $offer) => $this->summary($offer, $user))->values()->all();
    }

    /**
     * Offers the person may order from or at least look at, in force now: published inside the organization or shared
     * with the person's units. Filters: supplier organization, presenting unit, text.
     *
     * @param  array{supplier?: int|string|null, unit?: string|null, q?: string|null}  $filters
     * @return array{offers: list<array<string, mixed>>, suppliers: list<array<string, mixed>>, units: list<array<string, mixed>>}
     */
    public function availableList(User $user, array $filters = []): array
    {
        Gate::forUser($user)->authorize('viewAny', Offer::class);

        $base = $this->visibility->query($user)
            ->where('status', OfferStatus::Published->value)
            ->whereHas('currentVersion', fn (Builder $version) => $version
                ->where(fn (Builder $from) => $from->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
                ->where(fn (Builder $to) => $to->whereNull('valid_to')->orWhere('valid_to', '>=', now())));

        $all = (clone $base)->with('organization:id,name')->get(['id', 'organization_id', 'unit_ref']);

        $offers = $base
            ->when(! empty($filters['supplier']), fn (Builder $query) => $query->where('organization_id', (int) $filters['supplier']))
            ->when(! empty($filters['unit']) && UnitRef::parse($filters['unit']) !== null, fn (Builder $query) => $query->where('unit_ref', $filters['unit']))
            ->when(! empty($filters['q']), fn (Builder $query) => $query->whereHas('currentVersion', fn (Builder $version) => $version->where('title', 'like', '%'.addcslashes(mb_substr((string) $filters['q'], 0, 100), '%_\\').'%')))
            ->with(['currentVersion', 'organization:id,name'])
            ->orderByDesc('id')->limit(200)->get();

        return [
            'offers' => $offers->map(fn (Offer $offer) => $this->summary($offer, $user))->values()->all(),
            'suppliers' => $all->pluck('organization')->unique('id')->map(fn (Organization $organization) => ['id' => $organization->id, 'name' => $organization->name])->values()->all(),
            'units' => $all->map(fn (Offer $offer) => ['ref' => $offer->unit_ref, 'name' => (string) $offer->unit()?->find($offer->organization_id)?->name, 'organization_id' => $offer->organization_id])->unique('ref')->values()->all(),
        ];
    }

    /**
     * An offer for a person who may see it. A seller (who manages the unit) also gets the draft, the versions and the
     * list of recipients; a recipient gets only the published version, their own prices and the units they can order for.
     *
     * @return array<string, mixed>
     */
    public function show(User $user, Offer $offer): array
    {
        Gate::forUser($user)->authorize('view', $offer);

        $isSeller = Gate::forUser($user)->allows('manage', $offer);
        $offer->loadMissing(['currentVersion.items', 'currentVersion.attachments', 'organization:id,name']);

        $data = [
            ...$this->summary($offer, $user),
            'is_seller' => $isSeller,
            'version' => $offer->currentVersion === null ? null : $this->versionData($offer->currentVersion),
            'order_units' => $isSeller ? [] : $this->orders->orderableUnits($user, $offer),
        ];

        if ($isSeller) {
            $draft = $offer->versions()->where('status', OfferVersionStatus::Draft->value)->with(['items', 'attachments'])->first();

            $data['draft'] = $draft === null ? null : $this->versionData($draft);
            $data['versions'] = $offer->versions()->get(['id', 'version_no', 'status', 'published_at'])->map(fn (OfferVersion $version) => [
                'id' => $version->id,
                'version_no' => $version->version_no,
                'status' => $version->status->value,
                'published_at' => $version->published_at?->toIso8601String(),
            ])->all();
            $data['shares'] = $offer->shares()->orderByDesc('id')->get()->map(fn (OfferShare $share) => [
                'id' => $share->id,
                'unit' => (string) $share->unit()?->find($share->recipient_organization_id)?->name,
                'organization' => (string) Organization::query()->whereKey($share->recipient_organization_id)->value('name'),
                'allow_order' => $share->allow_order,
                'shared_at' => $share->shared_at->toIso8601String(),
                'revoked_at' => $share->revoked_at?->toIso8601String(),
            ])->all();
            $data['recipient_units'] = $this->recipientUnits($offer);
            $data['can_edit'] = in_array($offer->status, [OfferStatus::Draft, OfferStatus::Published, OfferStatus::Withdrawn], true);
        }

        return $data;
    }

    /**
     * Warehouses the person may present offers from.
     *
     * @return list<array{ref: string, name: string}>
     */
    public function sellerUnits(User $user): array
    {
        return collect($this->access->managedUnits($user))
            ->filter(fn (UnitRef $unit) => $unit->isWarehouse())
            ->map(fn (UnitRef $unit) => ['ref' => $unit->ref(), 'name' => (string) $unit->find((int) $user->organization_id)?->name])
            ->values()->all();
    }

    /** @return array<string, mixed> */
    public function summary(Offer $offer, User $user): array
    {
        $offer->loadMissing('currentVersion');
        $version = $offer->currentVersion ?? $offer->versions()->first();

        return [
            'id' => $offer->id,
            'number' => $offer->number,
            'title' => $version?->title,
            'status' => $offer->displayStatus(),
            'shared_internally' => $offer->shared_internally,
            'supplier' => (string) ($offer->organization?->name ?? Organization::query()->whereKey($offer->organization_id)->value('name')),
            'supplier_id' => $offer->organization_id,
            'unit' => (string) $offer->unit()?->find($offer->organization_id)?->name,
            'unit_ref' => $offer->unit_ref,
            'currency' => $version?->currency,
            'valid_to' => $version?->valid_to?->toIso8601String(),
            'version_no' => $offer->currentVersion?->version_no,
            'has_draft' => $offer->status === OfferStatus::Draft || $offer->versions()->where('status', OfferVersionStatus::Draft->value)->exists(),
        ];
    }

    /** @return array<string, mixed> */
    private function versionData(OfferVersion $version): array
    {
        return [
            'id' => $version->id,
            'version_no' => $version->version_no,
            'title' => $version->title,
            'description' => $version->description,
            'currency' => $version->currency,
            'valid_from' => $version->valid_from?->format('Y-m-d\TH:i'),
            'valid_to' => $version->valid_to?->format('Y-m-d\TH:i'),
            'delivery_terms' => $version->delivery_terms,
            'published_at' => $version->published_at?->toIso8601String(),
            'items' => $version->items->map(fn (OfferItem $item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'barcode' => $item->barcode,
                'unit_of_measure' => $item->unit_of_measure,
                'unit_price' => $item->unit_price,
                'vat_rate' => $item->vat_rate,
                'min_qty' => $item->min_qty,
                'qty_step' => $item->qty_step,
                'max_qty' => $item->max_qty,
            ])->values()->all(),
            'attachments' => $version->attachments->map(fn (OfferAttachment $attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'size' => $attachment->size,
            ])->values()->all(),
        ];
    }

    /**
     * Units of partner organizations the offer can be shared with now (the scope of the active cooperations of this
     * organization as the supplier, where the presenting unit is in the scope).
     *
     * @return list<array{ref: string, name: string, organization: string}>
     */
    private function recipientUnits(Offer $offer): array
    {
        return TradeRelationshipUnit::query()
            ->where('side', 'buyer')
            ->whereNull('revoked_at')
            ->whereHas('relationship', fn (Builder $relationship) => $relationship
                ->where('supplier_organization_id', $offer->organization_id)
                ->where('status', 'active')
                ->whereHas('units', fn (Builder $unit) => $unit->where('side', 'supplier')->where('unit_ref', $offer->unit_ref)->whereNull('revoked_at')))
            ->get()
            ->map(fn (TradeRelationshipUnit $row) => [
                'ref' => $row->unit_ref,
                'name' => (string) $row->unit()?->find($row->organization_id)?->name,
                'organization' => (string) Organization::query()->whereKey($row->organization_id)->value('name'),
            ])->values()->all();
    }

    /** The buyer unit in the scope of an active cooperation that covers the offer's presenting unit (or null). */
    private function cooperationUnit(Offer $offer, string $ref): ?TradeRelationshipUnit
    {
        return TradeRelationshipUnit::query()
            ->where('side', 'buyer')
            ->where('unit_ref', $ref)
            ->whereNull('revoked_at')
            ->whereHas('relationship', fn (Builder $relationship) => $relationship
                ->where('supplier_organization_id', $offer->organization_id)
                ->where('status', 'active')
                ->whereHas('units', fn (Builder $unit) => $unit->where('side', 'supplier')->where('unit_ref', $offer->unit_ref)->whereNull('revoked_at')))
            ->first();
    }

    private function ensureEditable(Offer $offer): void
    {
        if (in_array($offer->status, [OfferStatus::Archived], true)) {
            throw ValidationException::withMessages(['offer' => 'Zarchiwizowanej oferty nie można zmieniać.']);
        }
    }

    private function newDraft(Offer $offer, int $versionNo, User $user, ?OfferVersion $from = null): OfferVersion
    {
        $version = OfferVersion::create([
            'offer_id' => $offer->id,
            'version_no' => $versionNo,
            'status' => OfferVersionStatus::Draft->value,
            'draft_key' => $offer->id,
            'title' => $from?->title ?? 'Nowa oferta',
            'description' => $from?->description,
            'currency' => $from?->currency ?? 'PLN',
            'valid_from' => $from?->valid_from,
            'valid_to' => $from?->valid_to,
            'delivery_terms' => $from?->delivery_terms,
            'created_by' => $user->id,
        ]);

        if ($from !== null) {
            foreach ($from->items as $item) {
                OfferItem::create([...$item->only(['sku', 'name', 'barcode', 'unit_of_measure', 'unit_price', 'vat_rate', 'min_qty', 'qty_step', 'max_qty', 'position']), 'offer_version_id' => $version->id]);
            }
        }

        return $version;
    }

    /** The draft of the offer; a new one (copied from the published version) when there is none. */
    private function ensureDraft(Offer $offer, User $user): OfferVersion
    {
        $draft = $offer->versions()->where('status', OfferVersionStatus::Draft->value)->first();

        if ($draft !== null) {
            return $draft;
        }

        $latest = $offer->versions()->first();

        return $this->newDraft($offer, ($latest?->version_no ?? 0) + 1, $user, $offer->currentVersion?->load('items'));
    }

    /**
     * Writes the form data into the draft: its fields and positions (the positions are replaced as a whole).
     *
     * @param  array<string, mixed>  $data
     */
    private function fillDraft(OfferVersion $version, array $data): void
    {
        $version->update([
            'title' => Str::limit(trim((string) ($data['title'] ?? $version->title)), 200, ''),
            'description' => isset($data['description']) ? Str::limit((string) $data['description'], 5000, '') : $version->description,
            'currency' => strtoupper((string) ($data['currency'] ?? $version->currency)),
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'] ?? null,
            'delivery_terms' => isset($data['delivery_terms']) ? Str::limit((string) $data['delivery_terms'], 3000, '') : $version->delivery_terms,
        ]);

        if (! array_key_exists('items', $data)) {
            return;
        }

        $version->items()->delete();

        foreach (array_values($data['items']) as $index => $item) {
            if ((float) $item['unit_price'] <= 0 || (float) ($item['min_qty'] ?? 1) <= 0 || (float) ($item['qty_step'] ?? 1) <= 0
                || (isset($item['max_qty']) && $item['max_qty'] !== '' && (float) $item['max_qty'] < (float) ($item['min_qty'] ?? 1))) {
                throw ValidationException::withMessages(['items' => 'Pozycja "'.($item['name'] ?? '').'" ma nieprawidłową cenę lub ilości.']);
            }

            OfferItem::create([
                'offer_version_id' => $version->id,
                'sku' => $item['sku'] ?? null,
                'name' => Str::limit(trim((string) $item['name']), 200, ''),
                'barcode' => $item['barcode'] ?? null,
                'unit_of_measure' => $item['unit_of_measure'] ?? 'szt.',
                'unit_price' => $item['unit_price'],
                'vat_rate' => $item['vat_rate'] ?? null,
                'min_qty' => $item['min_qty'] ?? 1,
                'qty_step' => $item['qty_step'] ?? 1,
                'max_qty' => ($item['max_qty'] ?? '') === '' ? null : $item['max_qty'],
                'position' => $index,
            ]);
        }
    }

    private function assertPublishable(OfferVersion $draft): void
    {
        if ($draft->items()->count() === 0) {
            throw ValidationException::withMessages(['offer' => 'Oferta musi mieć co najmniej jedną pozycję.']);
        }

        if (trim($draft->title) === '' || $draft->title === 'Nowa oferta') {
            throw ValidationException::withMessages(['offer' => 'Nadaj ofercie tytuł.']);
        }

        if ($draft->valid_to !== null && $draft->valid_to->isPast()) {
            throw ValidationException::withMessages(['offer' => 'Termin ważności oferty jest w przeszłości.']);
        }

        if ($draft->valid_from !== null && $draft->valid_to !== null && $draft->valid_to->lt($draft->valid_from)) {
            throw ValidationException::withMessages(['offer' => 'Oferta kończy się przed rozpoczęciem.']);
        }
    }

    private function contentHash(OfferVersion $version): string
    {
        return hash('sha256', json_encode([
            $version->only(['title', 'description', 'currency', 'valid_from', 'valid_to', 'delivery_terms']),
            $version->items()->get()->map->only(['sku', 'name', 'unit_of_measure', 'unit_price', 'vat_rate', 'min_qty', 'qty_step', 'max_qty'])->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
