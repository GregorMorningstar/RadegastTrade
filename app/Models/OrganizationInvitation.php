<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Organization owner invitation sent by the administrator.
 *
 * Safeguards: a link with a random token, a 6-digit code from the email (only its hash is stored),
 * matching the account's email address, expiry and a limit on wrong code attempts.
 */
#[Fillable(['organization_id', 'shop_headquarter_id', 'warehouse_headquarter_id', 'shop_branch_id', 'warehouse_branch_id', 'email', 'phone', 'role', 'invited_by', 'expires_at', 'accepted_at', 'is_active', 'revoked_at', 'revoked_by'])]
#[Hidden(['verification_code'])]
class OrganizationInvitation extends Model
{
    public const MAX_ATTEMPTS = 5;

    protected static function booted(): void
    {
        static::creating(function (OrganizationInvitation $invitation) {
            $invitation->code ??= Str::random(64);
        });
    }

    /**
     * Creates the invitation and returns it along with the plain verification code (only to be sent by email).
     * Without an organization it is an invitation to create a new one: the invited person provides the name and details.
     *
     * With a headquarters it is an invitation for its manager (role SHOP_OWNER / WAREHOUSE_OWNER).
     *
     * @return array{0: OrganizationInvitation, 1: string}
     */
    public static function issue(
        ?Organization $organization,
        string $email,
        User $inviter,
        UserRole $role = UserRole::ORGANIZATION_OWNER,
        ShopHeadquarter|WarehouseHeadquarter|null $headquarter = null,
        ?string $phone = null,
        ShopBranch|WarehouseBranch|null $branch = null,
    ): array {
        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $invitation = new self([
            'organization_id' => $organization?->id,
            'email' => strtolower($email),
            'phone' => $phone,
            'shop_headquarter_id' => $headquarter instanceof ShopHeadquarter ? $headquarter->id : ($branch instanceof ShopBranch ? $branch->shop_headquarter_id : null),
            'warehouse_headquarter_id' => $headquarter instanceof WarehouseHeadquarter ? $headquarter->id : ($branch instanceof WarehouseBranch ? $branch->warehouse_headquarter_id : null),
            'shop_branch_id' => $branch instanceof ShopBranch ? $branch->id : null,
            'warehouse_branch_id' => $branch instanceof WarehouseBranch ? $branch->id : null,
            'role' => $role,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(3),
        ]);
        $invitation->verification_code = Hash::make($plainCode);
        $invitation->save();

        return [$invitation, $plainCode];
    }

    /**
     * Generates a new key (the old one stops working) and resets the wrong-attempt counter.
     * Returns the plain key only to be sent by email.
     */
    public function regenerateCode(): string
    {
        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->forceFill([
            'verification_code' => Hash::make($plainCode),
            'attempts' => 0,
        ])->save();

        return $plainCode;
    }

    public function checkCode(string $code): bool
    {
        return Hash::check($code, $this->verification_code);
    }

    /**
     * Tries a key from the e-mail under a lock on the invitation's row. Guesses are therefore counted one after another:
     * a burst of parallel requests cannot try more keys than the limit before the invitation locks (counting outside a
     * lock lets all of them through, since each one still sees the old counter).
     *
     * @return 'ok'|'wrong'|'locked'|'unavailable' unavailable: accepted, withdrawn or expired meanwhile
     */
    public function attemptCode(string $code): string
    {
        $result = DB::transaction(function () use ($code) {
            $current = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($current === null || ! $current->is_active || $current->accepted_at !== null || $current->isExpired()) {
                return 'unavailable';
            }

            if ($current->isLocked()) {
                return 'locked';
            }

            if ($current->checkCode($code)) {
                return 'ok';
            }

            $current->increment('attempts');

            // increment() also raises the attribute in memory
            return $current->attempts >= self::MAX_ATTEMPTS ? 'locked' : 'wrong';
        });

        $this->refresh();

        return $result;
    }

    public function isPending(): bool
    {
        return $this->is_active && $this->accepted_at === null && ! $this->isExpired() && ! $this->isLocked();
    }

    /**
     * Revokes the invitation, recording when and by whom.
     */
    public function revoke(User $by): void
    {
        $this->forceFill([
            'is_active' => false,
            'revoked_at' => now(),
            'revoked_by' => $by->id,
        ])->save();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Accepts the invitation: stores the date and deactivates it.
     */
    public function markAccepted(): void
    {
        $this->forceFill(['accepted_at' => now(), 'is_active' => false])->save();
    }

    /**
     * The user who revoked the invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isLocked(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function isFor(User $user): bool
    {
        return strtolower($user->email) === strtolower($this->email);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<ShopHeadquarter, $this>
     */
    public function shopHeadquarter(): BelongsTo
    {
        return $this->belongsTo(ShopHeadquarter::class);
    }

    /**
     * @return BelongsTo<WarehouseHeadquarter, $this>
     */
    public function warehouseHeadquarter(): BelongsTo
    {
        return $this->belongsTo(WarehouseHeadquarter::class);
    }

    /**
     * @return BelongsTo<ShopBranch, $this>
     */
    public function shopBranch(): BelongsTo
    {
        return $this->belongsTo(ShopBranch::class);
    }

    /**
     * @return BelongsTo<WarehouseBranch, $this>
     */
    public function warehouseBranch(): BelongsTo
    {
        return $this->belongsTo(WarehouseBranch::class);
    }

    /**
     * The headquarters the invitation is for (null for an organization owner invitation).
     */
    public function headquarter(): ShopHeadquarter|WarehouseHeadquarter|null
    {
        return $this->shopHeadquarter ?? $this->warehouseHeadquarter;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'is_active' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
