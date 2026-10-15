<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page a deactivated account lands on: who switched it off and when, and the chat with that person.
 */
class AccountInactiveController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // an active account has nothing to do here
        if ($user->is_active !== false) {
            return redirect()->route($user->role->dashboardRoute());
        }

        /** @var User|null $by */
        $by = $user->deactivatedBy;

        return Inertia::render('account/inactive', [
            'deactivatedBy' => $by ? ['id' => $by->id, 'name' => $by->name, 'role' => $by->role->label()] : null,
            'deactivatedAt' => $user->deactivated_at?->setTimezone($user->last_login_timezone ?: config('app.timezone'))->format('d.m.Y H:i'),
        ]);
    }
}
