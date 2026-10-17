<?php

namespace App\Models;

use App\Concerns\HasTeams;
use App\Enums\UserRole;
use App\Models\Concerns\GeneratesBarcode;
use App\Observers\UserObserver;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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
// Role, organization, unit, activity and barcode are set by the backend (forceFill), never by mass assignment
#[Fillable(['name', 'email', 'phone', 'avatar_path', 'last_seen_at', 'password', 'current_team_id', 'terms_accepted_at', 'privacy_accepted_at', 'last_login_at', 'last_login_timezone'])]
#[ObservedBy(UserObserver::class)]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @var list<string> */
    protected $appends = ['avatar_url'];

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
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'role' => UserRole::class,
        ];
    }

    /**
     * Profile photo URL; the default avatar when there is no photo.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn () => $this->avatar_path
            ? Storage::disk('public')->url($this->avatar_path)
            : asset('images/default-avatar.svg'));
    }

    /**
     * Whether the user was active in the last two minutes (a tab with the app open).
     */
    public function isActive(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subSeconds(120));
    }

    /**
     * Last login time in the time zone of the place the user logged in from.
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
     * Who switched the account off (null when it is active or the person no longer exists).
     *
     * @return BelongsTo<User, $this>
     */
    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    /**
     * The organization the user belongs to (e.g. after accepting an invitation).
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The shop headquarters managed by the manager.
     *
     * @return BelongsTo<ShopHeadquarter, $this>
     */
    public function shopHeadquarter(): BelongsTo
    {
        return $this->belongsTo(ShopHeadquarter::class);
    }

    /**
     * The shop branch managed by the branch leader.
     *
     * @return BelongsTo<ShopBranch, $this>
     */
    public function shopBranch(): BelongsTo
    {
        return $this->belongsTo(ShopBranch::class);
    }

    /**
     * The warehouse branch managed by the branch leader.
     *
     * @return BelongsTo<WarehouseBranch, $this>
     */
    public function warehouseBranch(): BelongsTo
    {
        return $this->belongsTo(WarehouseBranch::class);
    }

    /**
     * The warehouse headquarters managed by the manager.
     *
     * @return BelongsTo<WarehouseHeadquarter, $this>
     */
    public function warehouseHeadquarter(): BelongsTo
    {
        return $this->belongsTo(WarehouseHeadquarter::class);
    }
}
