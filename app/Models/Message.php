<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chat message between two people in the same organization.
 */
#[Fillable(['organization_id', 'sender_id', 'recipient_id', 'body', 'read_at'])]
class Message extends Model
{
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /**
     * Message data for the client (REST and broadcast).
     *
     * @return array{id: int, sender_id: int, recipient_id: int, body: string, created_at: string, read_at: string|null}
     */
    public function toChatArray(): array
    {
        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'recipient_id' => $this->recipient_id,
            'body' => $this->body,
            'created_at' => $this->created_at->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
        ];
    }
}
