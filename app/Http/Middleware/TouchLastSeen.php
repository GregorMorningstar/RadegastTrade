<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records the logged-in user's activity (at most once a minute) so the chat can show who is active.
 */
class TouchLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subSeconds(60)))) {
            // without model events and without changing updated_at
            User::whereKey($user->id)->toBase()->update(['last_seen_at' => now()]);
            $user->setAttribute('last_seen_at', now());
        }

        return $next($request);
    }
}
