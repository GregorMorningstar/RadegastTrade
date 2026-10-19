<?php

namespace App\Services;

use App\Enums\HeadquarterType;
use App\Enums\TradeInvitationStatus;
use App\Enums\TradeRelationshipStatus;
use App\Enums\TradeSide;
use App\Models\TradeInvitation;
use App\Models\TradeInvitationUnit;
use App\Models\TradeRelationship;
use App\Models\TradeRelationshipUnit;
use App\Models\User;
use App\Notifications\TradeInvitationNotification;
use App\Support\TradeAccess;
use App\Support\UnitRef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Trade cooperation between organizations: invitations, the cooperation with its scope of units, suspending and ending.
 *
 * A cooperation is directional (supplier → buyer) and covers only the units named in it on each side; a unit created
 * later is not added. Shops only buy, so the supplier side contains warehouse units only. An invitation never changes
 * who belongs to which organization; it is answered by a person who represents the invited organization.
 */
class TradeRelationshipService
{
    public const INVITATION_DAYS = 14;

    public function __construct(private readonly TradeAccess $access, private readonly AuditLogger $audit)
    {
        //
    }

    /**
     * Sends an invitation. The invited organization is found by the e-mail of one of its people (an organization that
     * exists in the system); the server never takes an organization or a foreign unit from the form. Returns null
     * when the address belongs to no organization, and the caller shows the same message either way, so the form cannot
     * be used to find out who is in the system.
     *
     * @param  array{inviter_role: string, invitee_email: string, units?: array<int, string>, all_current?: bool, message?: string|null}  $data
     */
    public function invite(User $inviter, array $data): ?TradeInvitation
    {
        Gate::forUser($inviter)->authorize('invite', TradeInvitation::class);

        $role = TradeSide::tryFrom((string) ($data['inviter_role'] ?? ''));

        if ($role === null) {
            throw ValidationException::withMessages(['inviter_role' => 'Wybierz, czy Twoja organizacja przedstawia oferty, czy zamawia.']);
        }

        $units = $this->ownScope($inviter, $role, $data['units'] ?? [], (bool) ($data['all_current'] ?? false));

        $invitee = User::query()
            ->whereNotNull('organization_id')
            ->where('is_active', true)
            ->where('email', mb_strtolower(trim((string) $data['invitee_email'])))
            ->first();

        if ($invitee === null || $invitee->organization_id === $inviter->organization_id) {
            return null;
        }

        $exists = TradeInvitation::query()
            ->where('inviter_organization_id', $inviter->organization_id)
            ->where('invitee_organization_id', $invitee->organization_id)
            ->where('inviter_role', $role->value)
            ->where('status', TradeInvitationStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['invitee_email' => 'Takie zaproszenie już czeka na odpowiedź.']);
        }

        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($inviter, $invitee, $role, $units, $data, $token) {
            $invitation = TradeInvitation::create([
                'inviter_organization_id' => $inviter->organization_id,
                'inviter_role' => $role->value,
                'invitee_organization_id' => $invitee->organization_id,
                'invitee_email' => $invitee->email,
                'invitee_scope' => 'open',
                'message' => isset($data['message']) ? Str::limit(trim((string) $data['message']), 500, '') : null,
                'token_hash' => TradeInvitation::hashToken($token),
                'expires_at' => now()->addDays(self::INVITATION_DAYS),
                'status' => TradeInvitationStatus::Pending->value,
                'invited_by' => $inviter->id,
            ]);

            foreach ($units as $unit) {
                TradeInvitationUnit::create(['invitation_id' => $invitation->id, 'side' => 'inviter', 'unit_ref' => $unit->ref(), ...$unit->columns()]);
            }

            $this->audit->record($inviter, $inviter->organization_id, 'trade.invitation.sent', $invitation, [
                'invitee_organization_id' => $invitee->organization_id,
                'inviter_role' => $role->value,
                'units' => array_map(fn (UnitRef $unit) => $unit->ref(), $units),
            ]);

            return $invitation;
        });

        $invitee->notify(new TradeInvitationNotification(
            $token,
            (string) $inviter->organization?->name,
            $role === TradeSide::Supplier ? 'przedstawianie Ci ofert' : 'zamawianie w Twoich hurtowniach i sklepach',
            (string) $invitation->message,
            $invitation->expires_at->format('d.m.Y H:i'),
        ));

        return $invitation;
    }

    public function findByToken(string $token): ?TradeInvitation
    {
        return strlen($token) === 64 ? TradeInvitation::query()->where('token_hash', TradeInvitation::hashToken($token))->first() : null;
    }

    /**
     * Accepts an invitation. The person has to represent the invited organization and chooses the units of that
     * organization that take part (all of them only when they are the owner and say so explicitly).
     *
     * @param  array{units?: array<int, string>, all_current?: bool}  $data
     */
    public function accept(User $user, string $token, array $data): TradeRelationship
    {
        return DB::transaction(function () use ($user, $token, $data) {
            $invitation = $this->lockPending($token);

            Gate::forUser($user)->authorize('represent', $invitation);
            $this->assertInviterStillEntitled($invitation);

            $side = $invitation->inviteeSide();
            $units = $this->chosenUnits($user, $side, $data['units'] ?? [], (bool) ($data['all_current'] ?? false));

            [$supplierOrg, $buyerOrg] = $side === TradeSide::Buyer
                ? [$invitation->inviter_organization_id, $invitation->invitee_organization_id]
                : [$invitation->invitee_organization_id, $invitation->inviter_organization_id];

            $relationship = TradeRelationship::query()
                ->where('supplier_organization_id', $supplierOrg)
                ->where('buyer_organization_id', $buyerOrg)
                ->lockForUpdate()
                ->first();

            if ($relationship === null) {
                $relationship = TradeRelationship::create([
                    'supplier_organization_id' => $supplierOrg,
                    'buyer_organization_id' => $buyerOrg,
                    'status' => TradeRelationshipStatus::Active->value,
                    'invitation_id' => $invitation->id,
                    'started_at' => now(),
                ]);
            } else {
                // a suspended or ended cooperation is restarted by a new, accepted invitation
                $relationship->update([
                    'status' => TradeRelationshipStatus::Active->value,
                    'invitation_id' => $invitation->id,
                    'started_at' => now(),
                    'suspended_at' => null,
                    'suspended_by' => null,
                    'ended_at' => null,
                    'ended_by' => null,
                    'status_reason' => null,
                ]);
            }

            $inviterUnits = $invitation->units()->where('side', 'inviter')->pluck('unit_ref')->map(fn (string $ref) => UnitRef::parse($ref))->filter()->all();

            $this->addUnits($relationship, $invitation->inviter_role, $invitation->inviter_organization_id, $inviterUnits, $invitation->invited_by);
            $this->addUnits($relationship, $side, (int) $user->organization_id, $units, $user->id);

            $invitation->update([
                'status' => TradeInvitationStatus::Accepted->value,
                'responded_by' => $user->id,
                'responded_at' => now(),
                'relationship_id' => $relationship->id,
            ]);

            $this->audit->record($user, $user->organization_id, 'trade.invitation.accepted', $invitation, [
                'relationship_id' => $relationship->id,
                'units' => array_map(fn (UnitRef $unit) => $unit->ref(), $units),
            ]);

            return $relationship;
        });
    }

    public function reject(User $user, string $token): void
    {
        DB::transaction(function () use ($user, $token) {
            $invitation = $this->lockPending($token);

            Gate::forUser($user)->authorize('represent', $invitation);

            $invitation->update(['status' => TradeInvitationStatus::Rejected->value, 'responded_by' => $user->id, 'responded_at' => now()]);
            $this->audit->record($user, $user->organization_id, 'trade.invitation.rejected', $invitation);
        });
    }

    public function cancel(User $user, TradeInvitation $invitation): void
    {
        Gate::forUser($user)->authorize('cancel', $invitation);

        DB::transaction(function () use ($user, $invitation) {
            $invitation = TradeInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if (! $invitation->isPending()) {
                throw ValidationException::withMessages(['invitation' => 'Tego zaproszenia nie można już cofnąć.']);
            }

            $invitation->update(['status' => TradeInvitationStatus::Cancelled->value, 'responded_by' => $user->id, 'responded_at' => now()]);
            $this->audit->record($user, $user->organization_id, 'trade.invitation.cancelled', $invitation);
        });
    }

    public function suspend(User $user, TradeRelationship $relationship, ?string $reason = null): void
    {
        $this->changeStatus($user, $relationship, [TradeRelationshipStatus::Active], TradeRelationshipStatus::Suspended, $reason, 'trade.relationship.suspended');
    }

    public function resume(User $user, TradeRelationship $relationship): void
    {
        $this->changeStatus($user, $relationship, [TradeRelationshipStatus::Suspended], TradeRelationshipStatus::Active, null, 'trade.relationship.resumed');
    }

    /** Ending is final: it keeps the history and the orders, and a new invitation is needed to cooperate again. */
    public function end(User $user, TradeRelationship $relationship, ?string $reason = null): void
    {
        $this->changeStatus($user, $relationship, [TradeRelationshipStatus::Active, TradeRelationshipStatus::Suspended], TradeRelationshipStatus::Ended, $reason, 'trade.relationship.ended');
    }

    /** Takes one unit of the person's side out of the scope (the person manages that unit). */
    public function revokeUnit(User $user, TradeRelationship $relationship, string $unitRef): void
    {
        Gate::forUser($user)->authorize('view', $relationship);

        $unit = UnitRef::parse($unitRef);
        $side = $relationship->sideOf((int) $user->organization_id);

        abort_if($unit === null || $side === null || ! $this->access->manages($user, $unit), 403);

        $row = $relationship->units()->where('side', $side->value)->where('unit_ref', $unit->ref())->whereNull('revoked_at')->first();

        abort_if($row === null, 404);

        $row->update(['revoked_at' => now(), 'revoked_by' => $user->id, 'active_key' => null]);
        $this->audit->record($user, $user->organization_id, 'trade.relationship.unit_revoked', $relationship, ['unit' => $unit->ref()]);
    }

    /**
     * What the cooperation page shows: the cooperations of the person's organization, the invitations it sent and the
     * pending ones it received. Of the other organization only its name and the units that are in the scope.
     *
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        Gate::forUser($user)->authorize('viewAny', TradeRelationship::class);

        $organizationId = (int) $user->organization_id;

        $relationships = TradeRelationship::query()
            ->where(fn ($query) => $query->where('supplier_organization_id', $organizationId)->orWhere('buyer_organization_id', $organizationId))
            ->with(['supplier:id,name', 'buyer:id,name', 'units' => fn ($units) => $units->whereNull('revoked_at')])
            ->orderByDesc('id')
            ->get()
            ->map(function (TradeRelationship $relationship) use ($user, $organizationId) {
                $mine = $relationship->sideOf($organizationId);
                $partner = $mine === TradeSide::Supplier ? $relationship->buyer : $relationship->supplier;

                return [
                    'id' => $relationship->id,
                    'status' => $relationship->status->value,
                    'status_label' => $relationship->status->label(),
                    'my_side' => $mine?->value,
                    'my_side_label' => $mine?->label(),
                    'partner' => $partner?->name,
                    'my_units' => $this->unitNames($relationship->units->where('side', $mine)->all(), $organizationId),
                    'partner_units' => $this->unitNames($relationship->units->where('side', $mine?->opposite())->all(), (int) $partner?->id),
                    'can_manage' => Gate::forUser($user)->allows('manage', $relationship),
                    'started_at' => $relationship->started_at?->toIso8601String(),
                ];
            })->values()->all();

        $sent = TradeInvitation::query()
            ->where('inviter_organization_id', $organizationId)
            ->when(! $this->access->isOwner($user), fn ($query) => $query->where('invited_by', $user->id))
            ->with('inviteeOrganization:id,name')
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (TradeInvitation $invitation) => [
                'id' => $invitation->id,
                'partner' => $invitation->inviteeOrganization?->name,
                'role' => $invitation->inviter_role->value,
                'role_label' => $invitation->inviter_role === TradeSide::Supplier ? 'Przedstawiasz oferty' : 'Zamawiasz',
                'status' => $invitation->effectiveStatus()->value,
                'status_label' => $invitation->effectiveStatus()->label(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'can_cancel' => $invitation->isPending() && Gate::forUser($user)->allows('cancel', $invitation),
            ])->values()->all();

        return [
            'relationships' => $relationships,
            'sent' => $sent,
            'can_invite' => Gate::forUser($user)->allows('invite', TradeInvitation::class),
            'is_owner' => $this->access->isOwner($user),
            // the units the person may put in the scope of an invitation (only warehouses can be on the supplier side)
            'units' => collect($this->access->managedUnits($user))->map(fn (UnitRef $unit) => [
                'ref' => $unit->ref(),
                'name' => (string) $unit->find($organizationId)?->name,
                'email' => $unit->find($organizationId)?->email,
                'kind' => $this->kind($unit),
                'warehouse' => $unit->isWarehouse(),
            ])->values()->all(),
        ];
    }

    /**
     * What the person sees on an invitation's page: who invites and how, the status, and which units of their own
     * organization they may put in the scope. No data of the other organization except its name.
     *
     * @return array<string, mixed>
     */
    public function invitationView(User $user, TradeInvitation $invitation): array
    {
        $side = $invitation->inviteeSide();
        $represents = Gate::forUser($user)->allows('represent', $invitation);

        return [
            'status' => $invitation->effectiveStatus()->value,
            'status_label' => $invitation->effectiveStatus()->label(),
            'inviter' => $invitation->inviterOrganization?->name,
            'inviter_message' => $invitation->message,
            'inviter_role' => $invitation->inviter_role->value,
            'my_side' => $side->value,
            'my_side_label' => $side->label(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'represents' => $represents,
            'is_owner' => $represents && $this->access->isOwner($user),
            'units' => $represents ? $this->selectableUnits($user, $side) : [],
        ];
    }

    /**
     * The person who sent the invitation must still be allowed to offer these units: an invitation lives for days, and
     * in that time they may have been deactivated, moved or lost the unit. Otherwise accepting it would put units into a
     * cooperation on the authority of somebody who no longer has it.
     */
    private function assertInviterStillEntitled(TradeInvitation $invitation): void
    {
        $inviter = User::query()->find($invitation->invited_by);
        $stale = ValidationException::withMessages(['invitation' => 'To zaproszenie jest nieaktualne: osoba, która je wysłała, nie ma już uprawnień do wskazanych jednostek. Poproś o nowe.']);

        if ($inviter === null || $inviter->organization_id !== $invitation->inviter_organization_id || ! $this->access->canTrade($inviter)) {
            throw $stale;
        }

        $refs = $invitation->units()->where('side', 'inviter')->pluck('unit_ref');

        foreach ($refs as $ref) {
            $unit = UnitRef::parse($ref);

            if ($unit === null || $unit->find($invitation->inviter_organization_id) === null || ! $this->access->manages($inviter, $unit)) {
                throw $stale;
            }
        }
    }

    private function changeStatus(User $user, TradeRelationship $relationship, array $from, TradeRelationshipStatus $to, ?string $reason, string $action): void
    {
        Gate::forUser($user)->authorize('manage', $relationship);

        DB::transaction(function () use ($user, $relationship, $from, $to, $reason, $action) {
            $locked = TradeRelationship::query()->whereKey($relationship->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['relationship' => 'Tej współpracy nie można w ten sposób zmienić.']);
            }

            $locked->update(match ($to) {
                TradeRelationshipStatus::Suspended => ['status' => $to->value, 'suspended_at' => now(), 'suspended_by' => $user->id, 'status_reason' => $reason],
                TradeRelationshipStatus::Ended => ['status' => $to->value, 'ended_at' => now(), 'ended_by' => $user->id, 'status_reason' => $reason],
                default => ['status' => $to->value, 'suspended_at' => null, 'suspended_by' => null, 'status_reason' => null],
            });

            $this->audit->record($user, $user->organization_id, $action, $locked, ['reason' => $reason]);
        });
    }

    private function lockPending(string $token): TradeInvitation
    {
        $invitation = strlen($token) === 64
            ? TradeInvitation::query()->where('token_hash', TradeInvitation::hashToken($token))->lockForUpdate()->first()
            : null;

        abort_if($invitation === null, 404);

        if (! $invitation->isPending()) {
            // a pending invitation past its date is "expired" by its effective status; nothing has to be stored
            throw ValidationException::withMessages(['invitation' => match ($invitation->effectiveStatus()) {
                TradeInvitationStatus::Accepted => 'To zaproszenie zostało już wykorzystane.',
                TradeInvitationStatus::Rejected => 'To zaproszenie zostało odrzucone.',
                TradeInvitationStatus::Cancelled => 'To zaproszenie zostało cofnięte.',
                default => 'To zaproszenie wygasło.',
            }]);
        }

        return $invitation;
    }

    /**
     * Units of the inviting organization put in the scope: those the person manages (the owner may add all current ones).
     *
     * @param  array<int, string>  $refs
     * @return list<UnitRef>
     */
    private function ownScope(User $user, TradeSide $role, array $refs, bool $allCurrent): array
    {
        return $this->resolveUnits($user, $role, $refs, $allCurrent, 'units');
    }

    /**
     * @param  array<int, string>  $refs
     * @return list<UnitRef>
     */
    private function chosenUnits(User $user, TradeSide $side, array $refs, bool $allCurrent): array
    {
        return $this->resolveUnits($user, $side, $refs, $allCurrent, 'units');
    }

    /**
     * Turns what came from the form into units of the person's own organization: each one has to exist there, to be of
     * the right kind for the side (the supplier side has warehouses only) and to be managed by the person; "all
     * current units" is for the owner only. Anything else is refused, never silently trimmed.
     *
     * @param  array<int, string>  $refs
     * @return list<UnitRef>
     */
    private function resolveUnits(User $user, TradeSide $side, array $refs, bool $allCurrent, string $field): array
    {
        $organizationId = (int) $user->organization_id;

        if ($allCurrent) {
            if (! $this->access->isOwner($user)) {
                throw ValidationException::withMessages([$field => 'Wszystkie jednostki może wskazać tylko właściciel organizacji.']);
            }

            $units = $this->access->allUnits($organizationId, $side === TradeSide::Supplier ? HeadquarterType::Warehouse : null);
        } else {
            $units = [];

            foreach (array_unique($refs) as $ref) {
                $unit = UnitRef::parse($ref);

                if ($unit === null || $unit->find($organizationId) === null || ! $this->access->manages($user, $unit)) {
                    throw ValidationException::withMessages([$field => 'Wskazano jednostkę, której nie możesz reprezentować.']);
                }

                if ($side === TradeSide::Supplier && ! $unit->isWarehouse()) {
                    throw ValidationException::withMessages([$field => 'Oferty przedstawiają tylko hurtownie; sklepy tylko zamawiają.']);
                }

                $units[] = $unit;
            }
        }

        if ($units === []) {
            throw ValidationException::withMessages([$field => 'Wybierz co najmniej jedną jednostkę.']);
        }

        return array_values($units);
    }

    /**
     * Adds units to a side of the cooperation (a unit that is already there is left alone).
     *
     * @param  list<UnitRef>  $units
     */
    private function addUnits(TradeRelationship $relationship, TradeSide $side, int $organizationId, array $units, ?int $grantedBy): void
    {
        foreach ($units as $unit) {
            $key = $relationship->id.':'.$side->value.':'.$unit->ref();

            if (TradeRelationshipUnit::query()->where('active_key', $key)->exists()) {
                continue;
            }

            TradeRelationshipUnit::create([
                'relationship_id' => $relationship->id,
                'side' => $side->value,
                'organization_id' => $organizationId,
                'unit_ref' => $unit->ref(),
                'granted_by' => $grantedBy,
                'active_key' => $key,
                ...$unit->columns(),
            ]);
        }
    }

    /**
     * @return list<array{ref: string, name: string, kind: string}> the units the person may put in the scope
     */
    private function selectableUnits(User $user, TradeSide $side): array
    {
        return collect($this->access->managedUnits($user))
            ->filter(fn (UnitRef $unit) => $side === TradeSide::Buyer || $unit->isWarehouse())
            ->map(fn (UnitRef $unit) => ['ref' => $unit->ref(), 'name' => (string) $unit->find((int) $user->organization_id)?->name, 'email' => $unit->find((int) $user->organization_id)?->email, 'kind' => $this->kind($unit)])
            ->values()->all();
    }

    /**
     * @param  array<int, TradeRelationshipUnit>  $rows
     * @return list<array{ref: string, name: string, kind: string}>
     */
    private function unitNames(array $rows, int $organizationId): array
    {
        return collect($rows)
            ->map(fn (TradeRelationshipUnit $row) => ($unit = $row->unit()) === null ? null : ['ref' => $unit->ref(), 'name' => (string) $unit->find($organizationId)?->name, 'kind' => $this->kind($unit)])
            ->filter()->values()->all();
    }

    private function kind(UnitRef $unit): string
    {
        return ($unit->isWarehouse() ? 'Hurtownia' : 'Sklep').($unit->isBranch() ? ' – placówka' : ' – siedziba');
    }
}
