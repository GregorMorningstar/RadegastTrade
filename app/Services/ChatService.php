<?php

namespace App\Services;

use App\Services\Contracts\ChatServiceInterface;
use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Models\Message;
use App\Enums\TradeSide;
use App\Models\TradeRelationship;
use App\Models\User;
use App\Support\TradeAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * One-to-one Messenger-style chat, limited to people in one organization.
 *
 * You can only talk to an account that has the same (non-empty) organization_id, and (after a cooperation is accepted)
 * to the people who manage the units of the partner organization that are in its scope, see partnerIds().
 */
class ChatService implements ChatServiceInterface
{
    public function __construct(private readonly TradeAccess $access)
    {
        //
    }

    public const MESSAGES_LIMIT = 100;

    /** How long after the last signal we show that someone is typing. */
    public const TYPING_SECONDS = 6;

    /**
     * Whether the user can use the chat at all (belongs to an organization, or is an administrator).
     */
    public function canUse(User $user): bool
    {
        return $user->organization_id !== null || $this->isAdmin($user);
    }

    /**
     * A conversation is allowed only with another person from the same organization.
     * The platform administrator is the exception: they can talk with people from any organization.
     */
    public function ensureCanChat(User $me, User $other): void
    {
        abort_unless($this->canUse($me) && $me->id !== $other->id, 403, 'Możesz rozmawiać tylko z osobami z Twojej organizacji.');

        // a person of another organization does not exist for the sender (404, so ids cannot be probed)
        abort_unless($this->canTalk($me, $other), 404);

        abort_unless($this->allowedForInactive($me, $other), 403, 'Konto jest wyłączone: możesz pisać tylko do osoby, która je wyłączyła, i do administratorów.');
    }

    /**
     * A deactivated account may talk only with the person who deactivated it and with the administrators.
     */
    private function allowedForInactive(User $me, User $other): bool
    {
        if ($me->is_active !== false) {
            return true;
        }

        return $other->id === $me->deactivated_by || $this->isAdmin($other);
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    private function canTalk(User $me, User $other): bool
    {
        if ($me->organization_id !== null && $other->organization_id === $me->organization_id) {
            return true;
        }

        if ($other->organization_id !== null && $other->organization_id !== $me->organization_id && in_array($other->id, $this->partnerIds($me), true)) {
            return true;
        }

        return ($this->isAdmin($me) && $other->organization_id !== null)
            || ($this->isAdmin($other) && $me->organization_id !== null);
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

        $partners = $this->partnerIds($me);

        return User::query()
            ->where(function ($query) use ($me, $partners) {
                if ($this->isAdmin($me)) {
                    // the administrator talks to people who belong to some organization
                    $query->whereNotNull('organization_id');
                } else {
                    // organization members, plus the platform administrators
                    $query->where('organization_id', $me->organization_id)->orWhereIn('role', [UserRole::Admin, UserRole::Manager])->orWhereIn('id', $partners);
                }
            })
            ->whereKeyNot($me->id)
            ->with('organization:id,name')
            ->get()
            ->filter(fn (User $user) => $this->allowedForInactive($me, $user))
            ->map(function (User $user) use ($last, $unread, $me) {
                $message = $last->get($user->id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role->label(),
                    // what the person is (for the filters and the icons of the contact list): owner, shop, warehouse or platform, and the level
                    ...$this->placement($user),
                    // the organization of a person from a partner organization (null for people of the own one)
                    'organization' => $user->organization_id !== null && $user->organization_id !== $me->organization_id && ! $this->isAdmin($me) ? $user->organization?->name : null,
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

    /**
     * People of partner organizations the person may talk to: when a cooperation (active or suspended) has a unit of the
     * person's own in its scope, the people who manage the partner's units in that scope, and the two who started it (the
     * one who invited and the one who answered). Only people who manage a unit in the scope take part, on both sides, so
     * the rule is the same from either end and ends with the cooperation.
     *
     * @return list<int>
     */
    private function partnerIds(User $me): array
    {
        if ($me->organization_id === null || $me->is_active === false || $this->isAdmin($me)) {
            return [];
        }

        $mine = array_map(fn ($unit) => $unit->ref(), $this->access->managedUnits($me));

        if ($mine === []) {
            return [];
        }

        $organizationId = (int) $me->organization_id;
        $ids = [];

        $relationships = TradeRelationship::query()
            ->where(fn ($query) => $query->where('supplier_organization_id', $organizationId)->orWhere('buyer_organization_id', $organizationId))
            ->whereIn('status', ['active', 'suspended'])
            ->with(['units' => fn ($rows) => $rows->whereNull('revoked_at'), 'invitation:id,invited_by,responded_by,inviter_organization_id'])
            ->get();

        foreach ($relationships as $relationship) {
            $side = $relationship->sideOf($organizationId);

            if ($side === null || $relationship->units->filter(fn ($row) => $row->side === $side && in_array($row->unit_ref, $mine, true))->isEmpty()) {
                continue;
            }

            $partnerOrganization = $side === TradeSide::Supplier ? $relationship->buyer_organization_id : $relationship->supplier_organization_id;

            foreach ($relationship->units->filter(fn ($row) => $row->side === $side->opposite()) as $row) {
                if (($unit = $row->unit()) !== null) {
                    foreach ($this->access->managersOf($unit, (int) $partnerOrganization) as $manager) {
                        $ids[$manager->id] = true;
                    }
                }
            }

            $invitation = $relationship->invitation;

            if ($invitation !== null) {
                $starter = $invitation->inviter_organization_id === $organizationId ? $invitation->responded_by : $invitation->invited_by;

                if ($starter !== null) {
                    $ids[$starter] = true;
                }
            }
        }

        return User::query()->whereIn('id', array_keys($ids))->where('organization_id', '!=', $organizationId)->where('is_active', true)->pluck('id')->all();
    }

    /**
     * Where the person stands in the structure: "kind" is owner / shop / warehouse / platform, "level" is organization /
     * headquarters / branch / worker / platform.
     *
     * @return array{kind: string, level: string}
     */
    private function placement(User $user): array
    {
        $role = $user->role;

        return match (true) {
            $role === UserRole::ORGANIZATION_OWNER => ['kind' => 'owner', 'level' => 'organization'],
            $role->isShop() || $role->isWarehouse() => [
                'kind' => $role->isShop() ? 'shop' : 'warehouse',
                'level' => match (true) {
                    HeadquarterType::forRole($role) !== null => 'headquarters',
                    HeadquarterType::forLeaderRole($role) !== null => 'branch',
                    default => 'worker',
                },
            ],
            default => ['kind' => 'platform', 'level' => 'platform'],
        };
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
            'organization_id' => $me->organization_id ?? $other->organization_id,
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

    public function trySend(User $from, User $to, string $body): bool
    {
        try {
            $this->send($from, $to, $body);

            return true;
        } catch (\Throwable $e) {
            // not allowed to talk (another organization, no cooperation) or the chat is down: the action goes on without it
            return false;
        }
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
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('sender_id', $a->id)->where('recipient_id', $b->id))
                ->orWhere(fn ($q) => $q->where('sender_id', $b->id)->where('recipient_id', $a->id)));
    }
}
