<?php

namespace App\Support;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ErrorPage
{
    /**
     * Strona błędu z animacją, która przekierowuje na dashboard roli (lub stronę główną dla gościa).
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
