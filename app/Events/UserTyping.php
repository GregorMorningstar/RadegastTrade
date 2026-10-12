<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone is typing a message to the recipient: a short signal on their private chat channel.
 */
class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $senderId, public int $recipientId)
    {
        //
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'user.typing';
    }

    /**
     * @return array{user_id: int}
     */
    public function broadcastWith(): array
    {
        return ['user_id' => $this->senderId];
    }
}
