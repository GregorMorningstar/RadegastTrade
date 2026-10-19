<?php

namespace App\Models;

use App\Enums\TradeInvitationStatus;
use App\Enums\TradeSide;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invitation to trade cooperation between two organizations (not an invitation of a person to an organization).
 * The token is stored only as a hash and is single use.
 *
 * @property int $id
 * @property int $inviter_organization_id
 * @property TradeSide $inviter_role
 * @property int $invitee_organization_id
 * @property TradeInvitationStatus $status
 */
class TradeInvitation extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'inviter_role' => TradeSide::class,
            'status' => TradeInvitationStatus::class,
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    /** @return BelongsTo<Organization, $this> */
    public function inviterOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'inviter_organization_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function inviteeOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'invitee_organization_id');
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return HasMany<TradeInvitationUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(TradeInvitationUnit::class, 'invitation_id');
    }

    /** The status as people see it: a pending invitation past its date is expired, even before anything stored it. */
    public function effectiveStatus(): TradeInvitationStatus
    {
        return $this->status === TradeInvitationStatus::Pending && $this->expires_at->isPast()
            ? TradeInvitationStatus::Expired
            : $this->status;
    }

    public function isPending(): bool
    {
        return $this->effectiveStatus() === TradeInvitationStatus::Pending;
    }

    /** The side of the relationship the invitee organization takes. */
    public function inviteeSide(): TradeSide
    {
        return $this->inviter_role->opposite();
    }
}
