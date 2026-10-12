<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\ErrorPage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Lets through only users with one of the given roles.
     * Usage: ->middleware('role:admin,manager')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        $allowed = array_map(
            fn (string $role) => UserRole::from($role),
            $roles,
        );

        if (in_array($user->role, $allowed, true)) {
            return $next($request);
        }

        abort_if($request->expectsJson(), 403);

        // A page with an animation that shortly redirects to the user's role dashboard
        return ErrorPage::redirecting($request, 'errors/forbidden', 403);
    }
}
