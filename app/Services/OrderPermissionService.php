<?php

namespace App\Services;

use App\Enums\OrderAbility;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\UnitOrderPermission;
use App\Models\User;
use App\Notifications\OrderPermissionCodeNotification;
use App\Support\TradeAccess;
use App\Support\UnitRef;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delegating the right to place orders for a unit (unit_order_permissions.manage). The owner delegates for every unit,
 * a headquarters manager for their headquarters and its branches, a branch leader for their branch: nobody delegates
 * more than they manage. The person who receives it may belong to any unit of the organization (a shop worker may be
 * authorised to order for another shop too). Granting takes two steps (a code from the e-mail of the person who grants, then the confirmation); granting and revoking
 * are recorded with who and when.
 */
class OrderPermissionService
{
    public function __construct(private readonly TradeAccess $access, private readonly AuditLogger $audit)
    {
        //
    }

    /** Wrong codes allowed before the pending authorisation is dropped. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Step one: checks everything about the authorisation and e-mails a code to the person who grants it. Nothing is saved
     * until the code comes back (confirmGrant). A new request replaces the previous pending one.
     */
    public function requestGrant(User $granter, string $unitRef, int $userId): void
    {
        [$unit, $grantee] = $this->validatedGrant($granter, $unitRef, $userId);
        $code = (string) random_int(100000, 999999);

        Cache::put(self::cacheKey($granter->id), [
            'hash' => ApiKey::hashOf($code),
            'unit' => $unit->ref(),
            'user_id' => $grantee->id,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(OrderPermissionCodeNotification::VALID_MINUTES)->getTimestamp(),
        ], now()->addMinutes(OrderPermissionCodeNotification::VALID_MINUTES));

        try {
            $granter->notify(new OrderPermissionCodeNotification($code, (string) $unit->find((int) $granter->organization_id)?->name, $grantee->name));
        } catch (\Throwable $e) {
            Cache::forget(self::cacheKey($granter->id));

            throw $e;
        }

        $this->audit->record($granter, $granter->organization_id, 'order_permission.code_requested', null, ['user_id' => $grantee->id, 'unit' => $unit->ref()]);
    }

    /**
     * Step two: the code from the e-mail. The authorisation is the one that was requested (the unit and the person are
     * remembered with the code, not taken from this request), it is checked again, and five wrong codes drop it.
     */
    public function confirmGrant(User $granter, string $code): UnitOrderPermission
    {
        $key = self::cacheKey($granter->id);
        $pending = Cache::get($key);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            Cache::forget($key);

            throw ValidationException::withMessages(['code' => 'Kod wygasł albo nie został wysłany. Wybierz osobę ponownie.']);
        }

        if (! hash_equals($pending['hash'], ApiKey::hashOf(trim($code)))) {
            $pending['attempts']++;

            if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);

                throw ValidationException::withMessages(['code' => 'Zbyt wiele błędnych kodów. Wybierz osobę ponownie.']);
            }

            Cache::put($key, $pending, now()->addSeconds(max(1, $pending['expires_at'] - now()->getTimestamp())));

            throw ValidationException::withMessages(['code' => 'Nieprawidłowy kod.']);
        }

        Cache::forget($key);

        // the rights may have changed in the meantime: everything is checked again
        [$unit, $grantee] = $this->validatedGrant($granter, $pending['unit'], (int) $pending['user_id']);

        return $this->persistGrant($granter, $unit, $grantee);
    }

    /** Drops the pending authorisation (the person changed their mind). */
    public function cancelPending(User $granter): void
    {
        Cache::forget(self::cacheKey($granter->id));
    }

    /**
     * The authorisation waiting for its code (for the page), or null.
     *
     * @return array{unit: string, unit_name: string, person: string}|null
     */
    public function pending(User $granter): ?array
    {
        $pending = Cache::get(self::cacheKey($granter->id));

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $unit = UnitRef::parse($pending['unit']);

        return [
            'unit' => $pending['unit'],
            'unit_name' => (string) $unit?->find((int) $granter->organization_id)?->name,
            'person' => (string) User::query()->where('organization_id', $granter->organization_id)->whereKey($pending['user_id'])->value('name'),
        ];
    }

    /**
     * What may be granted: the unit is the granter's organization's and managed by them, the person works in it.
     *
     * @return array{0: UnitRef, 1: User}
     */
    private function validatedGrant(User $granter, string $unitRef, int $userId): array
    {
        $unit = UnitRef::parse($unitRef);

        abort_if($unit === null || $unit->find((int) $granter->organization_id) === null, 404);

        $permission = new UnitOrderPermission(['organization_id' => $granter->organization_id, 'unit_ref' => $unit->ref()]);
        Gate::forUser($granter)->authorize('manage', $permission);

        // the person has to be of the same organization, active and have a role in a unit (not the platform staff)
        $grantee = User::query()
            ->where('organization_id', $granter->organization_id)
            ->where('is_active', true)
            ->whereIn('role', $this->unitRoles())
            ->find($userId);

        if ($grantee === null) {
            throw ValidationException::withMessages(['user_id' => 'Wskazana osoba nie należy do Twojej organizacji albo jest nieaktywna.']);
        }

        // only a person who works in this very unit: a warehouse worker cannot order for a shop, nor a worker of one shop for another
        if (! ($this->access->ownUnit($grantee)?->equals($unit) ?? false)) {
            throw ValidationException::withMessages(['user_id' => 'Do zamawiania dla jednostki można upoważnić tylko osobę, która w niej pracuje.']);
        }

        return [$unit, $grantee];
    }

    private function persistGrant(User $granter, UnitRef $unit, User $grantee): UnitOrderPermission
    {
        $key = $grantee->id.':'.$unit->ref().':'.OrderAbility::PlaceOrders->value;

        return DB::transaction(function () use ($granter, $unit, $grantee, $key) {
            $existing = UnitOrderPermission::query()->where('active_key', $key)->first();

            if ($existing !== null) {
                return $existing;
            }

            $permission = UnitOrderPermission::create([
                'organization_id' => $granter->organization_id,
                'user_id' => $grantee->id,
                'unit_ref' => $unit->ref(),
                'ability' => OrderAbility::PlaceOrders->value,
                'granted_by' => $granter->id,
                'granted_at' => now(),
                'active_key' => $key,
                ...$unit->columns(),
            ]);

            $this->audit->record($granter, $granter->organization_id, 'order_permission.granted', $permission, ['user_id' => $grantee->id, 'unit' => $unit->ref()]);

            return $permission;
        });
    }

    private static function cacheKey(int $granterId): string
    {
        return 'order-permission-grant:'.$granterId;
    }

    public function revoke(User $actor, UnitOrderPermission $permission): void
    {
        Gate::forUser($actor)->authorize('manage', $permission);

        abort_if($permission->revoked_at !== null, 404);

        $permission->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'active_key' => null]);
        $this->audit->record($actor, $actor->organization_id, 'order_permission.revoked', $permission, ['user_id' => $permission->user_id, 'unit' => $permission->unit_ref]);
    }

    /**
     * For the page: the units the person manages, who is authorised for each of them, and the people of that unit who may be.
     *
     * @return array{units: list<array<string, mixed>>, pending: array<string, string>|null}
     */
    public function overview(User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', UnitOrderPermission::class);

        $organizationId = (int) $actor->organization_id;
        $managed = $this->access->managedUnits($actor);
        $refs = array_map(fn (UnitRef $unit) => $unit->ref(), $managed);

        $permissions = UnitOrderPermission::query()
            ->where('organization_id', $organizationId)
            ->whereIn('unit_ref', $refs)
            ->whereNull('revoked_at')
            ->with(['user:id,name,email', 'grantedBy:id,name'])
            ->get()
            ->groupBy('unit_ref');

        $units = collect($managed)->map(fn (UnitRef $unit) => [
            'ref' => $unit->ref(),
            'name' => (string) $unit->find($organizationId)?->name,
            'kind' => ($unit->isWarehouse() ? 'Hurtownia' : 'Sklep').($unit->isBranch() ? ' – placówka' : ' – siedziba'),
            'authorised' => ($permissions->get($unit->ref()) ?? collect())->map(fn (UnitOrderPermission $permission) => [
                'id' => $permission->id,
                'user' => $permission->user?->name,
                'email' => $permission->user?->email,
                'granted_by' => $permission->grantedBy?->name,
                'granted_at' => $permission->granted_at->toIso8601String(),
            ])->values()->all(),
        ])->values()->all();

        // the people who may be authorised for a unit are the ones who work in it
        $people = User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('role', $this->unitRoles())
            ->orderBy('name')->limit(500)->get()
            ->groupBy(fn (User $user) => $this->access->ownUnit($user)?->ref());

        foreach ($units as &$unit) {
            $unit['candidates'] = ($people->get($unit['ref']) ?? collect())
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role->label()])
                ->values()->all();
        }

        unset($unit);

        return ['units' => $units, 'pending' => $this->pending($actor)];
    }

    /** @return list<string> roles of people who work in a unit */
    private function unitRoles(): array
    {
        return collect(UserRole::cases())
            ->filter(fn (UserRole $role) => $role->isShop() || $role->isWarehouse())
            ->map(fn (UserRole $role) => $role->value)
            ->values()->all();
    }
}
