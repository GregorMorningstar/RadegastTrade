<?php

namespace App\Models;

use App\Concerns\HasTeams;
use App\Enums\UserRole;
use App\Models\Concerns\GeneratesBarcode;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_timezone
 * @property-read Carbon|null $last_login_local
 */
#[Fillable(['name', 'email', 'password', 'current_team_id', 'role', 'barcode', 'organization_id', 'terms_accepted_at', 'privacy_accepted_at', 'last_login_at', 'last_login_timezone'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use GeneratesBarcode, HasFactory, HasTeams, Notifiable, TwoFactorAuthenticatable;

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
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
            'role' => UserRole::class,
        ];
    }

    /**
     * Czas ostatniego logowania w strefie czasowej miejsca, z którego się logowano.
     */
    protected function lastLoginLocal(): Attribute
    {
        return Attribute::get(fn () => $this->last_login_at?->copy()->setTimezone(
            $this->last_login_timezone ?: config('app.timezone'),
        ));
    }

    protected function barcodeKey(): string
    {
        return 'user';
    }

    /**
     * Organizacja, do której należy użytkownik (np. po przyjęciu zaproszenia).
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
