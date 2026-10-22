<?php

namespace App\Services\Contracts;

use App\Models\Message;
use App\Models\User;

interface ChatServiceInterface
{
    /**
     * Whether the user can use the chat at all (belongs to an organization).
     */
    public function canUse(User $user): bool;

    /**
     * A conversation is allowed only with another person from the same organization.
     */
    public function ensureCanChat(User $me, User $other): void;

    /**
     * People in the organization with unread counts, the last message and activity status.
     * Active first, then by last message and name.
     *
     * @return list<array<string, mixed>>
     */
    public function contacts(User $me): array;

    public function unreadTotal(User $me): int;

    /**
     * The latest messages of the conversation (oldest first).
     *
     * @return list<array<string, mixed>>
     */
    public function messages(User $me, User $other): array;

    public function send(User $me, User $other, string $body): Message;

    /**
     * An automatic message (about an order, an offer): sent only when the two may talk; otherwise nothing happens. Never throws.
     */
    public function trySend(User $from, User $to, string $body): bool;

    /**
     * Records that $me is typing to $other (expires after a few seconds without another signal).
     */
    public function typing(User $me, User $other): void;

    /**
     * Whether $writer is currently typing to $reader.
     */
    public function isTyping(User $writer, User $reader): bool;

    /**
     * Marks messages from $other as read.
     */
    public function markRead(User $me, User $other): void;
}
