<?php

namespace App\Support;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ErrorPage
{
    /**
     * Error page with an animation that redirects to the role dashboard (or the home page for a guest).
     *
     * @param  string  $component  np. 'errors/forbidden', 'errors/not-found'
     */
    public static function redirecting(Request $request, string $component, int $status): Response
    {
        $user = $request->user();

        return Inertia::render($component, [
            'redirectTo' => $user ? route($user->role->dashboardRoute()) : url('/'),
            'destination' => $user ? 'Twój panel ('.$user->role->label().')' : 'strona główna',
        ])->toResponse($request)->setStatusCode($status);
    }
}
