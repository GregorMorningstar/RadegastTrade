<?php

namespace App\Services;

use App\Enums\HeadquarterType;
use App\Enums\UnitLevel;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Notifications\ApiKeyNotification;
use App\Notifications\ApiKeyRemovalCodeNotification;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\Contracts\ApiKeyServiceInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * API keys of connectors: one key per unit (a headquarters or a branch), random, shown only in the e-mail, kept only as
 * a keyed hash. A new key can be made only after the old one has been removed, and removing needs a code from the
 * e-mail of the person who asks.
 */
class ApiKeyService implements ApiKeyServiceInterface
{
    public function __construct(private readonly ApiKeyRepositoryInterface $keys)
    {
        //
    }

    public function canManage(User $user): bool
    {
        return Gate::forUser($user)->allows('manage', ApiKey::class);
    }

    public function overview(User $user): array
    {
        if (! $this->canManage($user)) {
            return ['allowed' => false, 'api_url' => $this->apiUrl(), 'units' => []];
        }

        $organizationId = (int) $user->organization_id;
        $active = $this->keys->activeKeys($organizationId);

        return [
            'allowed' => true,
            'api_url' => $this->apiUrl(),
            'units' => collect($this->managedUnits($user))->map(function (array $unit) use ($active, $user) {
                $key = $active->get($unit['type']->value.':'.$unit['level']->value.':'.$unit['id']);

                return [
                    'type' => $unit['type']->value,
                    'level' => $unit['level']->value,
                    'id' => $unit['id'],
                    'name' => $unit['name'],
                    'headquarter' => $unit['headquarter'],
                    'group' => $unit['type'] === HeadquarterType::Shop ? 'Sklep' : 'Hurtownia',
                    'is_headquarters' => $unit['level'] === UnitLevel::Headquarters,
                    'key' => $key === null ? null : [
                        'name' => $key->name,
                        'prefix' => $key->prefix,
                        'created_at' => $key->created_at?->toIso8601String(),
                        'last_used_at' => $key->last_used_at?->toIso8601String(),
                        'last_used_ip' => $key->last_used_ip,
                        // a key generated before the copy was saved cannot be shown (generate a new one)
                        'can_reveal' => $key->key_encrypted !== null,
                        // only the person who asked for the code sees that it is waiting
                        'removal_pending' => $key->removal_code_hash !== null && $key->removal_requested_by === $user->id && $key->removal_code_expires_at?->isFuture() === true,
                    ],
                ];
            })->values()->all(),
        ];
    }

    public function issue(User $user, HeadquarterType $type, UnitLevel $level, int $unitId, string $name): array
    {
        $unit = $this->manageableUnit($user, $type, $level, $unitId);

        if ($this->keys->activeForUnit((int) $user->organization_id, $type, $level, $unitId) !== null) {
            throw ValidationException::withMessages(['api_key' => 'Ta jednostka ma już aktywny klucz API. Aby wygenerować nowy, najpierw usuń obecny (kod potwierdzający przyjdzie na Twój e-mail).']);
        }

        $name = trim($name) !== '' ? trim($name) : $unit->name;
        $key = ApiKey::KEY_PREFIX.Str::random(40);

        // the key is stored and e-mailed in one transaction: a failed e-mail leaves nothing behind
        try {
            DB::transaction(function () use ($user, $unit, $type, $level, $name, $key) {
                $this->keys->issue($user, $unit, $type, $level, $name, substr($key, 0, 12), ApiKey::hashOf($key), $key);

                $user->notify(new ApiKeyNotification($key, $unit->name, $name, $this->apiUrl()));
            });
        } catch (UniqueConstraintViolationException) {
            // two requests at once: the database lets only one key be active
            throw ValidationException::withMessages(['api_key' => 'Ta jednostka ma już aktywny klucz API.']);
        }

        return $this->overview($user);
    }

    public function requestRemoval(User $user, HeadquarterType $type, UnitLevel $level, int $unitId): array
    {
        $this->manageableUnit($user, $type, $level, $unitId);

        $key = $this->keys->activeForUnit((int) $user->organization_id, $type, $level, $unitId);

        if ($key === null) {
            throw ValidationException::withMessages(['api_key' => 'Ta jednostka nie ma aktywnego klucza API.']);
        }

        $code = (string) random_int(100000, 999999);

        DB::transaction(function () use ($user, $key, $code) {
            $this->keys->storeRemovalCode($key, $user, ApiKey::hashOf($code), now()->addMinutes(ApiKeyRemovalCodeNotification::VALID_MINUTES));

            $user->notify(new ApiKeyRemovalCodeNotification($code, $key->name));
        });

        return $this->overview($user);
    }

    public function confirmRemoval(User $user, HeadquarterType $type, UnitLevel $level, int $unitId, string $code): void
    {
        $this->manageableUnit($user, $type, $level, $unitId);

        $key = $this->keys->activeForUnit((int) $user->organization_id, $type, $level, $unitId);

        // the code works only for the person who asked for it
        if ($key === null || $key->removal_code_hash === null || $key->removal_code_expires_at === null || $key->removal_code_expires_at->isPast() || $key->removal_requested_by !== $user->id) {
            throw ValidationException::withMessages(['code' => 'Kod wygasł albo nie został wysłany. Poproś o nowy kod.']);
        }

        if (! hash_equals($key->removal_code_hash, ApiKey::hashOf(trim($code)))) {
            $attempts = $this->keys->failRemovalAttempt($key);

            throw ValidationException::withMessages(['code' => $attempts >= 5
                ? 'Zbyt wiele błędnych kodów. Poproś o nowy kod.'
                : 'Nieprawidłowy kod.']);
        }

        $this->keys->revoke($key, $user);
    }

    public function reveal(User $user, HeadquarterType $type, UnitLevel $level, int $unitId, string $password): string
    {
        $this->manageableUnit($user, $type, $level, $unitId);

        if (! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'Nieprawidłowe hasło.']);
        }

        $key = $this->keys->activeForUnit((int) $user->organization_id, $type, $level, $unitId);

        if ($key === null || $key->key_encrypted === null) {
            throw ValidationException::withMessages(['api_key' => 'Nie ma zapisanego klucza do pokazania. Usuń ten klucz i wygeneruj nowy.']);
        }

        $this->keys->markRevealed($key, $user);

        return $key->key_encrypted;
    }

    public function authenticate(string $key, ?string $ip = null): ?ApiKey
    {
        // a quick look at the shape of the key before the database is asked
        if (! str_starts_with($key, ApiKey::KEY_PREFIX) || strlen($key) !== 44) {
            return null;
        }

        $found = $this->keys->findActiveByHash(ApiKey::hashOf($key));

        if ($found !== null) {
            $this->keys->touch($found, $ip);
        }

        return $found;
    }

    public function describe(ApiKey $key): array
    {
        [$type, $level, $unit] = match (true) {
            $key->shop_headquarter_id !== null => ['Sklep', 'Siedziba', $key->shopHeadquarter],
            $key->warehouse_headquarter_id !== null => ['Hurtownia', 'Siedziba', $key->warehouseHeadquarter],
            $key->shop_branch_id !== null => ['Sklep', 'Placówka', $key->shopBranch],
            default => ['Hurtownia', 'Placówka', $key->warehouseBranch],
        };

        return [
            'organization' => (string) $key->organization?->name,
            'unit' => ['type' => $type, 'level' => $level, 'name' => (string) $unit?->name],
            'key' => ['name' => $key->name, 'prefix' => $key->prefix],
        ];
    }

    /**
     * The unit of the person's organization that the person manages (404 for a missing one or one of another
     * organization, 403 for one they do not manage).
     */
    private function manageableUnit(User $user, HeadquarterType $type, UnitLevel $level, int $unitId): ShopHeadquarter|WarehouseHeadquarter|ShopBranch|WarehouseBranch
    {
        abort_unless($this->canManage($user), 403);

        $unit = $this->keys->findUnit((int) $user->organization_id, $type, $level, $unitId);
        abort_if($unit === null, 404);

        Gate::forUser($user)->authorize('manageUnit', [ApiKey::class, $unit]);

        return $unit;
    }

    /**
     * @return list<array{type: HeadquarterType, level: UnitLevel, id: int, name: string, headquarter: ?string}>
     */
    private function managedUnits(User $user): array
    {
        $organizationId = (int) $user->organization_id;

        if ($user->role === UserRole::ORGANIZATION_OWNER) {
            return $this->keys->units($organizationId);
        }

        foreach (HeadquarterType::cases() as $type) {
            if ($user->role === $type->ownerRole()) {
                return $this->keys->units($organizationId, $type, $user->{$type->headquarterColumn()});
            }

            if ($user->role === $type->leaderRole()) {
                return $this->keys->units($organizationId, $type, null, $user->{$type->branchColumn()});
            }
        }

        return [];
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
