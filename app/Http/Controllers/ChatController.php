<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Contracts\ChatServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Messenger-style chat: the /chat page and the JSON API also used by the chat window in every panel.
 */
class ChatController extends Controller
{
    public function __construct(private readonly ChatServiceInterface $chat)
    {
        //
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('chat/index', [
            'canChat' => $this->chat->canUse($user),
            'organizationName' => $user->organization?->name,
        ]);
    }

    /**
     * People in the organization (with activity and unread counts). Polling itself also refreshes the user's activity.
     */
    public function contacts(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            // id of the logged-in account: the client detects an account switch (e.g. logging in in a second window sharing the same cookies)
            'me' => $user->id,
            'contacts' => $this->chat->contacts($user),
            'unread' => $this->chat->unreadTotal($user),
        ]);
    }

    public function messages(Request $request, User $user): JsonResponse
    {
        $this->chat->markRead($request->user(), $user);

        return response()->json([
            'messages' => $this->chat->messages($request->user(), $user),
            'typing' => $this->chat->isTyping($user, $request->user()),
        ]);
    }

    /**
     * The "typing" signal: sent every few seconds while someone is writing a message.
     */
    public function typing(Request $request, User $user): HttpResponse
    {
        $this->chat->typing($request->user(), $user);

        return response()->noContent();
    }

    public function send(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $message = $this->chat->send($request->user(), $user, $data['body']);

        return response()->json(['message' => $message->toChatArray()], 201);
    }
}
