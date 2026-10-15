<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated account (users.is_active = false) still logs in, but everything leads to the "account is not
 * active" page. Only that page, logging out and the chat (with the person who deactivated the account and with the
 * administrators, see ChatService) stay available.
 */
class EnsureUserIsActive
{
    /** Routes a deactivated account may still use. */
    private const ALLOWED_ROUTES = [
        'account.inactive',
        'logout',
        'chat.contacts',
        'chat.messages',
        'chat.send',
        'chat.typing',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_active !== false || $this->allowed($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Konto jest nieaktywne.'], 403);
        }

        return redirect()->route('account.inactive');
    }

    private function allowed(Request $request): bool
    {
        // the broadcasting authorization of the chat channel (authorized by routes/channels.php)
        return $request->routeIs(self::ALLOWED_ROUTES) || $request->is('broadcasting/auth');
    }
}
