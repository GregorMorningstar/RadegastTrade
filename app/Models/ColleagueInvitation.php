<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitation of a person of the same organization to the units of a cooperation. Nothing is granted until the invited
 * person accepts it with the code from their e-mail.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $invited_user_id
 * @property int|null $invited_by
 * @property array<int, string> $unit_refs
 * @property string $status
 */
class ColleagueInvitation extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_refs' => 'array',
            'consent_required' => 'boolean',
            'consent_requested_at' => 'datetime',
            'consented_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<User, $this> */
    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
