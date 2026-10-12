<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Zaproszenie właściciela organizacji wysyłane przez administratora.
 *
 * Zabezpieczenia: link z losowym tokenem, 6-cyfrowy kod z maila (w bazie tylko hash),
 * dopasowanie adresu e-mail konta, wygasanie oraz limit błędnych prób kodu.
 */
#[Fillable(['organization_id', 'email', 'role', 'invited_by', 'expires_at', 'accepted_at', 'is_active', 'revoked_at', 'revoked_by'])]
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
     * Tworzy zaproszenie i zwraca je razem z jawnym kodem weryfikacyjnym (tylko do wysłania mailem).
     * Bez organizacji to zaproszenie do założenia nowej: nazwę i dane podaje zapraszana osoba.
     *
     * @return array{0: OrganizationInvitation, 1: string}
     */
    public static function issue(?Organization $organization, string $email, User $inviter): array
    {
        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $invitation = new self([
            'organization_id' => $organization?->id,
            'email' => strtolower($email),
            'role' => UserRole::ORGANIZATION_OWNER,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(3),
        ]);
        $invitation->verification_code = Hash::make($plainCode);
        $invitation->save();

        return [$invitation, $plainCode];
    }

    /**
     * Generuje nowy klucz (stary przestaje działać) i zeruje licznik błędnych prób.
     * Zwraca jawny klucz tylko do wysłania mailem.
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

    public function isPending(): bool
    {
        return $this->is_active && $this->accepted_at === null && ! $this->isExpired() && ! $this->isLocked();
    }

    /**
     * Wycofuje zaproszenie, zapamiętując kiedy i przez kogo.
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
     * Przyjmuje zaproszenie: zapisuje datę i dezaktywuje je.
     */
    public function markAccepted(): void
    {
        $this->forceFill(['accepted_at' => now(), 'is_active' => false])->save();
    }

    /**
     * Użytkownik, który wycofał zaproszenie.
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
