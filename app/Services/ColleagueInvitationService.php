<?php

namespace App\Services;

use App\Enums\HeadquarterType;
use App\Enums\OrderAbility;
use App\Enums\UnitLevel;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\ColleagueInvitation;
use App\Models\UnitOrderPermission;
use App\Models\User;
use App\Notifications\ColleagueCodeNotification;
use App\Notifications\ColleagueInvitationNotification;
use App\Support\TradeAccess;
use App\Support\UnitRef;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inviting a person of the same organization to the units of a cooperation (the right to place orders for them).
 *
 * Only the owner and a headquarters manager invite, and only to units they manage. Nothing is granted by the invitation:
 * the invited person accepts it with a code from their own e-mail. When they are a branch leader, they first ask for
 * consent the person who manages them (the manager of their headquarters when there is one, otherwise the owner), and
 * that person confirms with a code from their e-mail. Everything is checked again when the code comes back.
 */
class ColleagueInvitationService
{
    public const INVITATION_DAYS = 7;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TradeAccess $access, private readonly AuditLogger $audit)
    {
        //
    }

    /** The owner of the organization and a headquarters manager. */
    public function canInvite(User $user): bool
    {
        return $this->access->managedUnits($user) !== []
            && ($this->access->isOwner($user) || HeadquarterType::forRole($user->role) !== null);
    }

    /**
     * @param  array<int, string>  $refs
     */
    public function invite(User $inviter, int $userId, array $refs): ColleagueInvitation
    {
        abort_unless($this->canInvite($inviter), 403);

        $invitee = $this->colleague($inviter, $userId);
        $inviteeUnit = $this->access->ownUnit($invitee);

        $units = [];

        foreach (array_unique($refs) as $ref) {
            $unit = UnitRef::parse($ref);

            if ($unit === null || $unit->find((int) $inviter->organization_id) === null || ! $this->access->manages($inviter, $unit)) {
                throw ValidationException::withMessages(['units' => 'Wskazano jednostkę, której nie możesz reprezentować.']);
            }

            // a warehouse person does not order for a shop and the other way round
            if ($inviteeUnit === null || $unit->type !== $inviteeUnit->type) {
                throw ValidationException::withMessages(['units' => 'Ta osoba pracuje w jednostce innego rodzaju (sklep / hurtownia) niż wskazane.']);
            }

            $units[] = $unit->ref();
        }

        if ($units === []) {
            throw ValidationException::withMessages(['units' => 'Wybierz co najmniej jedną jednostkę.']);
        }

        $pending = ColleagueInvitation::query()->where('invited_user_id', $invitee->id)->where('status', ColleagueInvitation::PENDING)->where('expires_at', '>', now())->exists();

        if ($pending) {
            throw ValidationException::withMessages(['user_id' => 'Ta osoba ma już zaproszenie, które czeka na odpowiedź.']);
        }

        $invitation = ColleagueInvitation::create([
            'organization_id' => $inviter->organization_id,
            'invited_user_id' => $invitee->id,
            'invited_by' => $inviter->id,
            'unit_refs' => $units,
            'status' => ColleagueInvitation::PENDING,
            'consent_required' => HeadquarterType::forLeaderRole($invitee->role) !== null,
            'expires_at' => now()->addDays(self::INVITATION_DAYS),
        ]);

        $this->audit->record($inviter, $inviter->organization_id, 'colleague_invitation.sent', $invitation, ['user_id' => $invitee->id, 'units' => $units]);
        $invitee->notify(new ColleagueInvitationNotification('invited', $inviter->name, $this->names($units, (int) $inviter->organization_id)));

        return $invitation;
    }

    public function cancel(User $user, int $id): void
    {
        $invitation = $this->pendingInOrganization($user, $id);

        abort_unless($this->access->isOwner($user) || $invitation->invited_by === $user->id, 403);

        $invitation->update(['status' => ColleagueInvitation::CANCELLED, 'responded_at' => now()]);
        $this->audit->record($user, $user->organization_id, 'colleague_invitation.cancelled', $invitation);
    }

    public function reject(User $user, int $id): void
    {
        $invitation = $this->mine($user, $id);

        $invitation->update(['status' => ColleagueInvitation::REJECTED, 'responded_at' => now()]);
        $this->audit->record($user, $user->organization_id, 'colleague_invitation.rejected', $invitation);
    }

    /** A leader asks the people who manage them for consent (they get an e-mail without a code). */
    public function requestConsent(User $user, int $id): void
    {
        $invitation = $this->mine($user, $id);

        if (! $invitation->consent_required) {
            throw ValidationException::withMessages(['invitation' => 'To zaproszenie nie wymaga zgody.']);
        }

        if ($invitation->consented_at !== null) {
            throw ValidationException::withMessages(['invitation' => 'Zgoda została już wyrażona.']);
        }

        $invitation->update(['consent_requested_at' => now()]);
        $units = $this->names($invitation->unit_refs, (int) $user->organization_id);

        foreach ($this->approversOf($user) as $approver) {
            $approver->notify(new ColleagueInvitationNotification('consent', $user->name, $units));
        }

        $this->audit->record($user, $user->organization_id, 'colleague_invitation.consent_requested', $invitation);
    }

    /** Step one of the consent: the code goes to the e-mail of the person who consents. */
    public function requestConsentCode(User $approver, int $id): void
    {
        $invitation = $this->approvableBy($approver, $id);

        $this->sendCode($approver, 'consent', $invitation, $invitation->invitedUser->name);
    }

    public function confirmConsent(User $approver, int $id, string $code): void
    {
        $invitation = $this->approvableBy($approver, $id);

        $this->verify(self::key('consent', $invitation->id, $approver->id), $code);

        $invitation->update(['consented_by' => $approver->id, 'consented_at' => now()]);
        $this->audit->record($approver, $approver->organization_id, 'colleague_invitation.consented', $invitation, ['user_id' => $invitation->invited_user_id]);
    }

    /** Step one of accepting: the code goes to the invited person. A leader needs the consent first. */
    public function requestAcceptCode(User $user, int $id): void
    {
        $invitation = $this->mine($user, $id);

        if ($invitation->consent_required && $invitation->consented_at === null) {
            throw ValidationException::withMessages(['invitation' => 'Najpierw poproś o zgodę właściciela lub siedzibę i poczekaj, aż ją potwierdzą.']);
        }

        $this->sendCode($user, 'accept', $invitation, $user->name);
    }

    /**
     * Step two: with the right code the person gets the right to place orders for the units. Their rights and the
     * rights of the one who invited are checked again, because the invitation may be days old.
     */
    public function accept(User $user, int $id, string $code): void
    {
        $invitation = $this->mine($user, $id);

        if ($invitation->consent_required && $invitation->consented_at === null) {
            throw ValidationException::withMessages(['invitation' => 'Brakuje zgody właściciela lub siedziby.']);
        }

        $this->verify(self::key('accept', $invitation->id, $user->id), $code);

        $inviter = User::query()->where('organization_id', $invitation->organization_id)->where('is_active', true)->find($invitation->invited_by);
        $stale = ValidationException::withMessages(['invitation' => 'To zaproszenie jest nieaktualne: osoba, która je wysłała, nie ma już uprawnień do wskazanych jednostek. Poproś o nowe.']);

        if ($inviter === null || ! $this->canInvite($inviter)) {
            throw $stale;
        }

        $inviteeUnit = $this->access->ownUnit($user);
        $units = [];

        foreach ($invitation->unit_refs as $ref) {
            $unit = UnitRef::parse($ref);

            if ($unit === null || $unit->find((int) $invitation->organization_id) === null || ! $this->access->manages($inviter, $unit) || $inviteeUnit?->type !== $unit->type) {
                throw $stale;
            }

            $units[] = $unit;
        }

        DB::transaction(function () use ($invitation, $user, $inviter, $units) {
            foreach ($units as $unit) {
                $key = $user->id.':'.$unit->ref().':'.OrderAbility::PlaceOrders->value;

                if (UnitOrderPermission::query()->where('active_key', $key)->exists()) {
                    continue;
                }

                UnitOrderPermission::create([
                    'organization_id' => $invitation->organization_id,
                    'user_id' => $user->id,
                    'unit_ref' => $unit->ref(),
                    'ability' => OrderAbility::PlaceOrders->value,
                    'granted_by' => $inviter->id,
                    'granted_at' => now(),
                    'active_key' => $key,
                    ...$unit->columns(),
                ]);
            }

            $invitation->update(['status' => ColleagueInvitation::ACCEPTED, 'responded_at' => now()]);
            $this->audit->record($user, $user->organization_id, 'colleague_invitation.accepted', $invitation, ['units' => $invitation->unit_refs, 'invited_by' => $inviter->id]);
        });
    }

    /**
     * For the cooperation page: whom and to what the person may invite, what they sent, what waits for their answer and
     * what waits for their consent.
     *
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $organizationId = (int) $user->organization_id;
        $canInvite = $this->canInvite($user);
        $label = fn (ColleagueInvitation $invitation) => $this->names($invitation->unit_refs, $organizationId);

        $sent = $canInvite
            ? ColleagueInvitation::query()
                ->where('organization_id', $organizationId)
                ->when(! $this->access->isOwner($user), fn ($query) => $query->where('invited_by', $user->id))
                ->with('invitedUser:id,name')->orderByDesc('id')->limit(30)->get()
                ->map(fn (ColleagueInvitation $invitation) => [
                    'id' => $invitation->id,
                    'person' => $invitation->invitedUser?->name,
                    'units' => $label($invitation),
                    'status' => $this->status($invitation),
                    'consent' => $invitation->consent_required ? ($invitation->consented_at !== null ? 'given' : ($invitation->consent_requested_at !== null ? 'requested' : 'needed')) : 'none',
                    'can_cancel' => $invitation->isPending(),
                ])->values()->all()
            : [];

        $received = ColleagueInvitation::query()
            ->where('invited_user_id', $user->id)->where('status', ColleagueInvitation::PENDING)->where('expires_at', '>', now())
            ->with('inviter:id,name')->orderByDesc('id')->get()
            ->map(fn (ColleagueInvitation $invitation) => [
                'id' => $invitation->id,
                'from' => $invitation->inviter?->name,
                'units' => $label($invitation),
                'consent_required' => $invitation->consent_required,
                'consent_requested' => $invitation->consent_requested_at !== null,
                'consented' => $invitation->consented_at !== null,
            ])->values()->all();

        return [
            'can_invite' => $canInvite,
            'people' => $canInvite ? $this->candidates($user) : [],
            'units' => $canInvite ? collect($this->access->managedUnits($user))->map(fn (UnitRef $unit) => [
                'ref' => $unit->ref(),
                'name' => (string) $unit->find($organizationId)?->name,
                'type' => $unit->type->value,
            ])->values()->all() : [],
            'sent' => $sent,
            'received' => $received,
            'consents' => $this->consentsFor($user),
            'valid_minutes' => ColleagueCodeNotification::VALID_MINUTES,
        ];
    }

    /**
     * What waits for this person (for the chat and the dashboard): invitations to units and consents they are asked for.
     *
     * @return list<array{kind: string, title: string, text: string, href: string, expires_at: string|null}>
     */
    public function inbox(User $user): array
    {
        if ($user->organization_id === null) {
            return [];
        }

        $organizationId = (int) $user->organization_id;
        $items = [];

        $received = ColleagueInvitation::query()
            ->where('invited_user_id', $user->id)->where('status', ColleagueInvitation::PENDING)->where('expires_at', '>', now())
            ->with('inviter:id,name')->orderByDesc('id')->limit(20)->get();

        foreach ($received as $invitation) {
            $items[] = [
                'kind' => 'colleague',
                'title' => 'Zaproszenie do jednostek od: '.$invitation->inviter?->name,
                'text' => 'Dostęp do składania zamówień dla: '.$this->names($invitation->unit_refs, $organizationId).'.',
                'href' => '/trade/partners',
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ];
        }

        foreach ($this->consentsFor($user) as $consent) {
            $items[] = [
                'kind' => 'consent',
                'title' => 'Prośba o zgodę: '.$consent['person'],
                'text' => 'Osoba prosi o zgodę na przyjęcie zaproszenia do: '.$consent['units'].'.',
                'href' => '/trade/partners',
                'expires_at' => null,
            ];
        }

        return $items;
    }

    /**
     * The invitations of leaders that wait for the consent of this person.
     *
     * @return list<array<string, mixed>>
     */
    private function consentsFor(User $approver): array
    {
        if ($approver->organization_id === null || $this->access->managedUnits($approver) === []) {
            return [];
        }

        return ColleagueInvitation::query()
            ->where('organization_id', $approver->organization_id)
            ->where('status', ColleagueInvitation::PENDING)->where('expires_at', '>', now())
            ->where('consent_required', true)->whereNull('consented_at')->whereNotNull('consent_requested_at')
            ->with('invitedUser')->orderBy('id')->get()
            ->filter(fn (ColleagueInvitation $invitation) => $invitation->invitedUser !== null && $this->approversOf($invitation->invitedUser)->contains('id', $approver->id))
            ->map(fn (ColleagueInvitation $invitation) => [
                'id' => $invitation->id,
                'person' => $invitation->invitedUser?->name,
                'units' => $this->names($invitation->unit_refs, (int) $approver->organization_id),
            ])->values()->all();
    }

    /**
     * Who may consent for a leader: the manager of their headquarters when it has one, otherwise the owner.
     *
     * @return Collection<int, User>
     */
    private function approversOf(User $leader): Collection
    {
        $organizationId = (int) $leader->organization_id;
        $own = $this->access->ownUnit($leader);
        $headquarterId = $own === null ? null : $own->find($organizationId)?->getAttribute($own->type->headquarterColumn());

        $approvers = $headquarterId === null
            ? User::query()->where('organization_id', $organizationId)->where('is_active', true)->where('role', UserRole::ORGANIZATION_OWNER->value)->get()
            : $this->access->managersOf(new UnitRef($own->type, UnitLevel::Headquarters, (int) $headquarterId), $organizationId);

        return $approvers->reject(fn (User $person) => $person->id === $leader->id)->values();
    }

    private function approvableBy(User $approver, int $id): ColleagueInvitation
    {
        $invitation = $this->pendingInOrganization($approver, $id);

        abort_unless(
            $invitation->consent_required && $invitation->consent_requested_at !== null && $invitation->consented_at === null
            && $invitation->invitedUser !== null && $this->approversOf($invitation->invitedUser)->contains('id', $approver->id),
            403,
        );

        return $invitation;
    }

    /** The pending, not expired invitation of the person's organization. */
    private function pendingInOrganization(User $user, int $id): ColleagueInvitation
    {
        $invitation = ColleagueInvitation::query()->where('organization_id', $user->organization_id)->with('invitedUser')->findOrFail($id);

        if (! $invitation->isPending()) {
            throw ValidationException::withMessages(['invitation' => 'To zaproszenie nie jest już aktualne.']);
        }

        return $invitation;
    }

    /** The invitation addressed to the person (nobody else sees it). */
    private function mine(User $user, int $id): ColleagueInvitation
    {
        $invitation = ColleagueInvitation::query()->where('invited_user_id', $user->id)->where('organization_id', $user->organization_id)->findOrFail($id);

        if (! $invitation->isPending()) {
            throw ValidationException::withMessages(['invitation' => 'To zaproszenie nie jest już aktualne.']);
        }

        return $invitation;
    }

    /** A person of the same organization who may be invited: active, with a role in a unit, not the inviting person. */
    private function colleague(User $inviter, int $userId): User
    {
        $colleague = User::query()
            ->where('organization_id', $inviter->organization_id)->where('is_active', true)->where('id', '!=', $inviter->id)
            ->whereIn('role', $this->unitRoles())
            ->find($userId);

        if ($colleague === null) {
            throw ValidationException::withMessages(['user_id' => 'Wskazana osoba nie należy do Twojej organizacji albo jest nieaktywna.']);
        }

        return $colleague;
    }

    /** @return list<array{id: int, name: string, role: string, type: string|null}> */
    private function candidates(User $user): array
    {
        return User::query()
            ->where('organization_id', $user->organization_id)->where('is_active', true)->where('id', '!=', $user->id)
            ->whereIn('role', $this->unitRoles())
            ->orderBy('name')->limit(500)->get()
            ->map(fn (User $person) => ['id' => $person->id, 'name' => $person->name, 'role' => $person->role->label(), 'type' => $this->access->ownUnit($person)?->type->value])
            ->values()->all();
    }

    private function sendCode(User $recipient, string $purpose, ColleagueInvitation $invitation, string $person): void
    {
        $code = (string) random_int(100000, 999999);
        $key = self::key($purpose, $invitation->id, $recipient->id);

        Cache::put($key, ['hash' => ApiKey::hashOf($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(ColleagueCodeNotification::VALID_MINUTES)->getTimestamp()], now()->addMinutes(ColleagueCodeNotification::VALID_MINUTES));

        try {
            $recipient->notify(new ColleagueCodeNotification($code, $purpose, $person, $this->names($invitation->unit_refs, (int) $invitation->organization_id)));
        } catch (\Throwable $e) {
            Cache::forget($key);

            throw $e;
        }

        $this->audit->record($recipient, $recipient->organization_id, 'colleague_invitation.code_requested', $invitation, ['purpose' => $purpose]);
    }

    /** Checks the code: one use, 15 minutes, five wrong ones drop it. */
    private function verify(string $key, string $code): void
    {
        $pending = Cache::get($key);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            Cache::forget($key);

            throw ValidationException::withMessages(['code' => 'Kod wygasł albo nie został wysłany. Poproś o nowy.']);
        }

        if (! hash_equals($pending['hash'], ApiKey::hashOf(trim($code)))) {
            $pending['attempts']++;

            if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);

                throw ValidationException::withMessages(['code' => 'Zbyt wiele błędnych kodów. Poproś o nowy.']);
            }

            Cache::put($key, $pending, now()->addSeconds(max(1, $pending['expires_at'] - now()->getTimestamp())));

            throw ValidationException::withMessages(['code' => 'Nieprawidłowy kod.']);
        }

        Cache::forget($key);
    }

    private static function key(string $purpose, int $invitationId, int $userId): string
    {
        return 'colleague-invitation:'.$purpose.':'.$invitationId.':'.$userId;
    }

    private function status(ColleagueInvitation $invitation): string
    {
        return $invitation->status === ColleagueInvitation::PENDING && $invitation->expires_at->isPast() ? 'expired' : $invitation->status;
    }

    /** @param  array<int, string>  $refs */
    private function names(array $refs, int $organizationId): string
    {
        return collect($refs)->map(fn (string $ref) => UnitRef::parse($ref)?->find($organizationId)?->name)->filter()->implode(', ');
    }

    /** @return list<string> */
    private function unitRoles(): array
    {
        return collect(UserRole::cases())->filter(fn (UserRole $role) => $role->isShop() || $role->isWarehouse())->map(fn (UserRole $role) => $role->value)->values()->all();
    }
}
