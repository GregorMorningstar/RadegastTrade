<?php

namespace App\Services;

use App\Services\Contracts\ChatServiceInterface;
use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * One-to-one Messenger-style chat, limited to people in one organization.
 *
 * You can only talk to an account that has the same (non-empty) organization_id.
 */
class ChatService implements ChatServiceInterface
{
    public const MESSAGES_LIMIT = 100;

    /** How long after the last signal we show that someone is typing. */
    public const TYPING_SECONDS = 6;

    /**
     * Whether the user can use the chat at all (belongs to an organization).
     */
    public function canUse(User $user): bool
    {
        return $user->organization_id !== null;
    }

    /**
     * A conversation is allowed only with another person from the same organization.
     */
    public function ensureCanChat(User $me, User $other): void
    {
        abort_unless(
            $this->canUse($me)
                && $me->id !== $other->id
                && $other->organization_id === $me->organization_id,
            403,
            'Możesz rozmawiać tylko z osobami z Twojej organizacji.',
        );
    }

    /**
     * People in the organization with unread counts, the last message and activity status.
     * Active first, then by last message and name.
     *
     * @return list<array<string, mixed>>
     */
    public function contacts(User $me): array
    {
        if (! $this->canUse($me)) {
            return [];
        }

        $last = Message::query()
            ->where('organization_id', $me->organization_id)
            ->where(fn ($query) => $query->where('sender_id', $me->id)->orWhere('recipient_id', $me->id))
            ->latest('id')
            ->limit(500)
            ->get()
            ->groupBy(fn (Message $message) => $message->sender_id === $me->id ? $message->recipient_id : $message->sender_id)
            ->map(fn (Collection $messages) => $messages->first());

        $unread = Message::query()
            ->where('recipient_id', $me->id)
            ->whereNull('read_at')
            ->selectRaw('sender_id, count(*) as total')
            ->groupBy('sender_id')
            ->pluck('total', 'sender_id');

        return User::query()
            ->where('organization_id', $me->organization_id)
            ->whereKeyNot($me->id)
            ->get()
            ->map(function (User $user) use ($last, $unread, $me) {
                $message = $last->get($user->id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role->label(),
                    'avatar_url' => $user->avatar_url,
                    'active' => $user->isActive(),
                    'typing' => $this->isTyping($user, $me),
                    'unread' => (int) ($unread[$user->id] ?? 0),
                    'last_message' => $message ? [
                        'body' => str($message->body)->limit(60)->toString(),
                        'mine' => $message->sender_id === $me->id,
                        'created_at' => $message->created_at->toIso8601String(),
                    ] : null,
                ];
            })
            ->sort(fn (array $a, array $b) => [$b['active'], $b['last_message']['created_at'] ?? '', $a['name']]
                <=> [$a['active'], $a['last_message']['created_at'] ?? '', $b['name']])
            ->values()
            ->all();
    }

    public function unreadTotal(User $me): int
    {
        return $this->canUse($me)
            ? Message::where('recipient_id', $me->id)->whereNull('read_at')->count()
            : 0;
    }

    /**
     * The latest messages of the conversation (oldest first).
     *
     * @return list<array<string, mixed>>
     */
    public function messages(User $me, User $other): array
    {
        $this->ensureCanChat($me, $other);

        return $this->conversation($me, $other)
            ->latest('id')
            ->limit(self::MESSAGES_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (Message $message) => $message->toChatArray())
            ->values()
            ->all();
    }

    public function send(User $me, User $other, string $body): Message
    {
        $this->ensureCanChat($me, $other);

        $message = Message::create([
            'organization_id' => $me->organization_id,
            'sender_id' => $me->id,
            'recipient_id' => $other->id,
            'body' => trim($body),
        ]);

        // a missing WebSocket server must not block sending: the client also refreshes the conversation by polling
        try {
            broadcast(new MessageSent($message))->toOthers();
        } catch (\Throwable $e) {
            report($e);
        }

        return $message;
    }

    /**
     * Records that $me is typing to $other (expires after a few seconds without another signal).
     */
    public function typing(User $me, User $other): void
    {
        $this->ensureCanChat($me, $other);

        Cache::put($this->typingKey($me, $other), true, now()->addSeconds(self::TYPING_SECONDS));

        try {
            broadcast(new UserTyping($me->id, $other->id))->toOthers();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Whether $writer is currently typing to $reader.
     */
    public function isTyping(User $writer, User $reader): bool
    {
        return Cache::has($this->typingKey($writer, $reader));
    }

    private function typingKey(User $writer, User $reader): string
    {
        return "chat-typing:{$writer->id}:{$reader->id}";
    }

    /**
     * Marks messages from $other as read.
     */
    public function markRead(User $me, User $other): void
    {
        $this->ensureCanChat($me, $other);

        Message::query()
            ->where('sender_id', $other->id)
            ->where('recipient_id', $me->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Message>
     */
    private function conversation(User $a, User $b)
    {
        return Message::query()
            ->where('organization_id', $a->organization_id)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('sender_id', $a->id)->where('recipient_id', $b->id))
                ->orWhere(fn ($q) => $q->where('sender_id', $b->id)->where('recipient_id', $a->id)));
    }
}
