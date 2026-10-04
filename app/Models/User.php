<?php

namespace App\Models;

use App\Concerns\HasTeams;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read UserRole $role
 */
#[Fillable(['name', 'email', 'password', 'current_team_id', 'role', 'barcode'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
        ];
    }

    protected static function booted(): void
    {
        // ID jest znane dopiero po zapisie, więc barcode generujemy w "created"
        static::created(function (User $user) {
            if (blank($user->barcode)) {
                $user->barcode = $user->generateBarcode();
                $user->saveQuietly();
            }
        });
    }

    /**
     * Prefiks EAN-13 dla kart użytkowników. Zakres 20-29 jest zarezerwowany przez GS1
     * na użytek wewnętrzny i nie jest przydzielany producentom, więc nie koliduje
     * z kodami produktów. Produkty wewnętrzne (bez EAN) powinny używać innego prefiksu, np. 21.
     */
    public const BARCODE_PREFIX = '20';

    /**
     * Generuje poprawny EAN-13: prefiks + ID dopełnione zerami + cyfra kontrolna.
     */
    public function generateBarcode(): string
    {
        $bodyLength = 12 - strlen(self::BARCODE_PREFIX);
        $body = str_pad((string) $this->id, $bodyLength, '0', STR_PAD_LEFT);

        if (strlen($body) > $bodyLength) {
            throw new \InvalidArgumentException('ID użytkownika jest za duże dla EAN-13.');
        }

        $base = self::BARCODE_PREFIX.$body;

        $sum = 0;
        foreach (str_split($base) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return $base.((10 - $sum % 10) % 10);
    }
}
